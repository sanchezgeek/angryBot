<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\MarketBuy\Exception\BuyIsNotSafeException;
use App\Application\UseCase\Trading\MarketBuy\MarketBuyHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\SpotBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Application\Settings\PushBuyOrderSettings;
use App\Bot\Application\Settings\TradingSettings;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Strategy\StopCreate;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Infrastructure\ByBit\Service\ByBitMarketService;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\ByBit\Service\Trade\ByBitOrderService;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use App\Settings\Application\Service\AppSettingsProvider;
use App\Worker\AppContext;
use DateTimeImmutable;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;

use function abs;
use function array_map;
use function max;
use function min;
use function random_int;
use function sprintf;
use function var_dump;

/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder\PushBuyOrdersCommonCasesTest */
/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder\CornerCases */
#[AsMessageHandler]
final class PushBuyOrdersHandler extends AbstractOrdersPusher
{
    public const STOP_ORDER_TRIGGER_DELTA = 37;

    # @todo | canUseSpot | must be calculated "on the fly" (required balance of funds must be provided by CheckPositionIsUnderLiquidationHandler)
    public const USE_SPOT_IF_BALANCE_GREATER_THAN = 65.5;
    public const USE_SPOT_AFTER_INDEX_PRICE_PNL_PERCENT = 70;
    public const TRANSFER_TO_SPOT_PROFIT_PART_WHEN_TAKE_PROFIT = 0.05;

    public const FIX_SUPPORT_ENABLED = false;
    public const FIX_MAIN_POSITION_ENABLED = false;
    public const FIX_SUPPORT_ONLY_FOR_BUY_OPPOSITE_ORDERS_AFTER_GOT_SL = true;

    /** "Reserved" - e.g. for avoiding liquidation */
    private const RESERVED_BALANCE = 0;
    public const SPOT_TRANSFER_ON_BUY_MULTIPLIER = 1.1;

    private array $lastCannotAffordAt = [];
    private array $lastCannotAffordAtPrice = [];

    private readonly LimiterInterface $ignoreBuyThrottlingLimiter;

    private readonly bool $useIsLastPriceOverIndexPriceCheck;
    private readonly bool $useIgnoreBuyCheckBasedOnTotalPositionLeverage;

    /**
     * @todo It must be separated service receiving some context of spot usage to make decision.
     * E.g. "transfer for make buy"
     * Or at least it must be some dto with set of parameters
     *
     * @todo Main check must be: "after this transfer ... will spot balance be greater than sufficient for avoiding liquidation (CheckPositionIsUnderLiquidationHandler)"
     * => estimated to transfer amount also must be provided for this check
     * it's the SECOND[2] big reason for separate this functionality (most probably it should be used not only here)
     */
    private function canUseSpot(Ticker $ticker, Position $position, SpotBalance $spotBalance, ?BuyOrder $buyOrder = null): bool
    {
        if ($spotBalance->available() === 0.00) {
            return false;
        }

        $hedge = $position->getHedge();

        # Force true if it's main position and position now in loss ?
        # @todo | This condition is questionable. Probably it was some corner case "in the moment", but it's not described here.
        # Questionable because anyway it must be USE_SPOT_IF_BALANCE_GREATER_THAN check
        if ($hedge?->isMainPosition($position) && $position->isPositionInLoss($ticker->indexPrice)) {
            return true;
        }

        # Check count of already done transfers
        $isSupportPositionForceBuyOrderAfterSl = $hedge?->isSupportPosition($position) && $buyOrder?->isOppositeBuyOrderAfterStopLoss();
        $maxTransfersCount = $isSupportPositionForceBuyOrderAfterSl ? 3 : 1;
        if ($buyOrder?->getSuccessSpotTransfersCount() >= $maxTransfersCount) {
            return false;
        }

        # Force true if it's support BuyOrder after SL
        if ($isSupportPositionForceBuyOrderAfterSl) {
            return true;
        }

        # Restrict transfer in case of available SPOT balance less than min required for avoid liquidation
        # @todo | Check must be performed on amount remaining after transfer instead of current balance (see [2])
        if ($spotBalance->available() > self::USE_SPOT_IF_BALANCE_GREATER_THAN) {
            return true;
        }

        # Skip check if "total position leverage" less than some leverage
        # @todo | what value must be used?
        # @todo | may be replace this check with "buyIsSafe" check (but this check probably already not passed and this scenario won't happen at all)
        if ($this->totalPositionLeverage($position, $ticker) < 60) {
            return true;
        }

        $indexPnlPercent = $ticker->indexPrice->getPnlPercentFor($position);

        return $indexPnlPercent >= self::USE_SPOT_AFTER_INDEX_PRICE_PNL_PERCENT;
    }

    private function canTakeProfit(Position $position, Ticker $ticker): bool
    {
        if ($this->settings->get(TradingSettings::TakeProfit_InCaseOf_Insufficient_Balance_Enabled) !== true) {
            return false;
        }

        $currentPnlPercent = $ticker->lastPrice->getPnlPercentFor($position);
        $minLastPricePnlPercentToTakeProfit = $this->settings->get(TradingSettings::TakeProfit_InCaseOf_Insufficient_Balance_After_Position_Pnl_Percent);

        return $currentPnlPercent >= $minLastPricePnlPercentToTakeProfit;
    }

    /**
     * @return BuyOrder[]
     */
    public function findOrdersNearTicker(Side $side, Ticker $ticker, ?Position $openedPosition = null): array
    {
        $volumeOrdering = $openedPosition && $this->canTakeProfit($openedPosition, $ticker)
            ? 'DESC' // To get bigger orders first (to take bigger profit by their volume)
            : 'ASC'; // Buy cheapest orders first

        $profitModifier = 0.0002 * $ticker->indexPrice->value();
        $lossModifier = 0.00036 * $ticker->indexPrice->value();

        $from = $side->isShort() ? $ticker->indexPrice->value() - $profitModifier : $ticker->indexPrice->value() - $lossModifier;
        $to = $side->isShort() ? $ticker->indexPrice->value() + $lossModifier : $ticker->indexPrice->value() + $profitModifier;

        return $this->buyOrderRepository->findActiveInRange(
            symbol: $ticker->symbol,
            side: $side,
            from: $from,
            to: $to,
            qbModifier: static function(QueryBuilder $qb) use ($side, $volumeOrdering) {
                QueryHelper::addOrder($qb, 'volume', $volumeOrdering);
                QueryHelper::addOrder($qb, 'price', $side->isShort() ? 'DESC' : 'ASC');
            },
        );
    }

    public function __invoke(PushBuyOrders $message): void
    {
        if ($this->marketService->isNowFundingFeesPaymentTime()) {
            return;
        }

        $side = $message->side;
        $symbol = $message->symbol;

        $ticker = $this->exchangeService->ticker($symbol);
        if (!$this->canBuy($ticker)) {
            return;
        }
        $index = $ticker->indexPrice; $last = $ticker->lastPrice;

        $position = $this->positionService->getPosition($symbol, $side);

        $orders = $this->findOrdersNearTicker($side, $ticker, $position);

        $ignoreBuy = null;
        if ($this->useIsLastPriceOverIndexPriceCheck && $position && $ticker->isLastPriceOverIndexPrice($side) && $last->deltaWith($index) >= 100) {
            $ignoreBuy = '~isLastPriceOverIndexPrice~ more than 100';
        } elseif ($this->useIgnoreBuyCheckBasedOnTotalPositionLeverage && $orders) {
            $ignoreBuy = $this->getNeedIgnoreBuyReason($position, $ticker);
        }

        if (!$position) {
            $position = new Position($side, $symbol, $index->value(), 0.05, 1000, 0, 13, 100);
        }

        /** @var BuyOrder $lastBuy For `CannotAffordOrderCost` exception processing */
        $lastBuy = null;
        try {
            $boughtOrders = [];
            foreach ($orders as $order) {
                if ($ignoreBuy && !$order->isForceBuyOrder()) {
                    $this->noticeAboutIgnoreBuy($position, $order, $ignoreBuy);

                    continue;
                }

                if ($order->mustBeExecuted($ticker)) {
                    $lastBuy = $order;
                    $this->buy($position, $ticker, $order);

                     if ($order->getExchangeOrderId()) {
                         $boughtOrders[] = new ExchangeOrder($symbol, $order->getVolume(), $last);
                     }
                }
            }

            if ($boughtOrders) {
                $spentCost = 0;
                foreach ($boughtOrders as $boughtOrder) {
                    $spentCost += $this->orderCostCalculator->totalBuyCost($boughtOrder, $position->leverage, $position->side)->value();
                }

                if ($spentCost > 0) {
                    $multiplier = $position->isSupportPosition() ? 0.5 : 1.8;
                    $amount = $spentCost * $multiplier;
                    $spotBalance = $this->exchangeAccountService->getSpotWalletBalance($symbol->associatedCoin());
                    if ($this->canUseSpot($ticker, $position, $spotBalance)) {
                        $this->transferToContract($spotBalance, $amount);
                    }
                }
            }
        } catch (CannotAffordOrderCostException $e) {
            $spotBalance = $this->exchangeAccountService->getSpotWalletBalance($symbol->associatedCoin());
            if ($this->canUseSpot($ticker, $position, $spotBalance, $lastBuy)) {
                $orderCost = $this->orderCostCalculator->totalBuyCost(new ExchangeOrder($symbol, $e->qty, $last), $position->leverage, $position->side)->value();
                $amount = $orderCost * self::SPOT_TRANSFER_ON_BUY_MULTIPLIER;
                if ($this->transferToContract($spotBalance, $amount)) {
                    $lastBuy->incSuccessSpotTransfersCounter();

                    $this->buyOrderRepository->save($lastBuy);
                    return;
                }
            }

            // @todo | check `contractBalance.total` >= `positions.totalIM` instead? To not cover existed losses by profit | + if some setting is set? | + rewrite with `force` rule.
            // @todo | but be careful: if there is no hedge then m.b. you want to get profit anyway?
            if (
                !$lastBuy->isOnlyIfHasAvailableBalanceContextSet()
                && $this->canTakeProfit($position, $ticker)
                && !(
                    ($hedge = $position->getHedge())?->isSupportPosition($position)
                    && $hedge->mainPosition->isPositionInLoss($last)
                )
            ) {
                $currentPnlPercent = $last->getPnlPercentFor($position);
                // @todo | move to some service | DRY (below)
                $volumeClosed = $symbol->roundVolumeUp($e->qty / ($currentPnlPercent * 0.5 / 100));
                $this->orderService->closeByMarket($position, $volumeClosed);

                if (!$position->isSupportPosition()) {
                    $expectedProfit = PnlHelper::getPnlInUsdt($position, $last, $volumeClosed);
                    $transferToSpotAmount = $expectedProfit * self::TRANSFER_TO_SPOT_PROFIT_PART_WHEN_TAKE_PROFIT;
                    $this->exchangeAccountService->interTransferFromContractToSpot($symbol->associatedCoin(), $transferToSpotAmount);
                }

                // reopen closed volume on further movement
                $distance = 100; if (!AppContext::isTest()) $distance += random_int(-20, 35);
                $reopenPrice = $position->isShort() ? $index->sub($distance) : $index->add($distance);
                $this->createBuyOrderHandler->handle(
                    new CreateBuyOrderEntryDto($symbol, $side, $volumeClosed, $reopenPrice->value(), [BuyOrder::ONLY_IF_HAS_BALANCE_AVAILABLE_CONTEXT => true])
                );

                return;
            }

            # mainPosition BuyOrder => grab profit from Support
            $priceToCalcLiqDiff = $position->isShort() ? max($position->entryPrice, $ticker->markPrice->value()) : min($position->entryPrice, $ticker->markPrice->value());
            if (self::FIX_SUPPORT_ENABLED && ($hedge = $position->getHedge())?->isMainPosition($position)
                && $lastBuy->getHedgeSupportFixationsCount() < 1
                && (
                    (
                        !self::FIX_SUPPORT_ONLY_FOR_BUY_OPPOSITE_ORDERS_AFTER_GOT_SL
                        && $lastBuy->isForceBuyOrder() // @todo separated context for allow fix support
                    )
                    || $lastBuy->isOppositeBuyOrderAfterStopLoss()
                )
                && ($mainPositionPnlPercent = $last->getPnlPercentFor($hedge->mainPosition)) < 30 # to prevent use supportPosition profit through the way to mainPosition :)
                && ($supportPnlPercent = $last->getPnlPercentFor($hedge->supportPosition)) > 228.228
                && (
                    $lastBuy->isForceBuyOrder()
                    || abs($priceToCalcLiqDiff - $position->liquidationPrice) > 2500               # position liquidation too far
                    || $this->hedgeService->isSupportSizeEnoughForSupportMainPosition($hedge)   # or support size enough
                )
            ) {
                $volume = $symbol->roundVolumeUp($e->qty / ($supportPnlPercent * 0.75 / 100));

                $this->orderService->closeByMarket($hedge->supportPosition, $volume);
                $lastBuy->incHedgeSupportFixationsCounter();

                $this->buyOrderRepository->save($lastBuy);
                return;
            }

            # support BuyOrder => grab profit from MainPosition
            if (self::FIX_MAIN_POSITION_ENABLED && ($hedge = $position->getHedge())?->isSupportPosition($position)
                && $lastBuy->getHedgeSupportFixationsCount() < 1
                && ($mainPositionPnlPercent = $last->getPnlPercentFor($hedge->mainPosition)) > 152.228 # main position at least must not be in loss
                && (
                    $lastBuy->isForceBuyOrder()
                    || !$this->hedgeService->isSupportSizeEnoughForSupportMainPosition($hedge)
                )
            ) {
                $volume = $symbol->roundVolumeUp($e->qty / ($mainPositionPnlPercent * 0.75 / 100));

                $this->orderService->closeByMarket($hedge->mainPosition, $volume);
                $lastBuy->incHedgeSupportFixationsCounter();

                $this->buyOrderRepository->save($lastBuy);
                return;
            }

            $this->lastCannotAffordAtPrice[$symbol->name] = $index->value();
            $this->lastCannotAffordAt[$symbol->name] = $this->clock->now();
        }
    }

    /**
     * @throws CannotAffordOrderCostException
     */
    private function buy(Position $position, Ticker $ticker, BuyOrder $order): void
    {
        try {
            $exchangeOrderId = $this->marketBuyHandler->handle(MarketBuyEntryDto::fromBuyOrder($order));
            $order->setExchangeOrderId($exchangeOrderId);
//            $this->events->dispatch(new BuyOrderPushedToExchange($order));

            if ($order->isWithOppositeOrder()) {
                $this->createStop($position, $ticker, $order);
            }

            if ($order->getVolume() <= 0.005) {
                $this->buyOrderRepository->remove($order);
                unset($order);
            }
        } catch (BuyIsNotSafeException) {
            OutputHelper::warning(sprintf('Skip buy %s|%s on %s.', $order->getVolume(), $order->getPrice(), $position));
        } catch (ApiRateLimitReached $e) {
            $this->logWarning($e);
            $this->sleep($e->getMessage());
        } catch (UnknownByBitApiErrorException|UnexpectedApiErrorException $e) {
            $this->logCritical($e);
        } finally {
            if (isset($order)) {
                $this->buyOrderRepository->save($order);
            }
        }
    }

    private function createStop(Position $position, Ticker $ticker, BuyOrder $buyOrder): void
    {
        $side = $position->side;
        $volume = $buyOrder->getVolume();

        $context = [];
        if (($hedge = $position->getHedge()) && $hedge->isSupportPosition($position)) {
            $context[Stop::CLOSE_BY_MARKET_CONTEXT] = true;
        }

        if ($specifiedStopDistance = $buyOrder->getStopDistance()) {
            $triggerPrice = $side->isShort() ? $buyOrder->getPrice() + $specifiedStopDistance : $buyOrder->getPrice() - $specifiedStopDistance;
            $this->stopService->create($position->symbol, $side, $triggerPrice, $volume, self::STOP_ORDER_TRIGGER_DELTA, $context);
            return;
        }

        $triggerPrice = null;

        // @todo | based on liquidation if position under hedge?
        $strategy = $this->getStopStrategy($position, $buyOrder, $ticker);

        $stopStrategy = $strategy['strategy'];
        $description = $strategy['description'];

        $basePrice = null;
        if ($stopStrategy === StopCreate::AFTER_FIRST_POSITION_STOP) {
            if ($firstPositionStop = $this->stopRepository->findFirstPositionStop($position)) {
                $basePrice = $firstPositionStop->getPrice();
            }
        } elseif ($stopStrategy === StopCreate::AFTER_FIRST_STOP_UNDER_POSITION || ($stopStrategy === StopCreate::ONLY_BIG_SL_AFTER_FIRST_STOP_UNDER_POSITION && $volume >= StopCreate::BIG_SL_VOLUME_STARTS_FROM)) {
            if ($firstStopUnderPosition = $this->stopRepository->findFirstStopUnderPosition($position)) {
                $basePrice = $firstStopUnderPosition->getPrice();
            } else {
                $stopStrategy = StopCreate::UNDER_POSITION;
            }
        }

        if ($stopStrategy === StopCreate::UNDER_POSITION || ($stopStrategy === StopCreate::ONLY_BIG_SL_UNDER_POSITION && $volume >= StopCreate::BIG_SL_VOLUME_STARTS_FROM)) {
            $positionPrice = \ceil($position->entryPrice);
            if ($ticker->isIndexAlreadyOverStop($side, $positionPrice)) {
                $basePrice = $side->isLong() ? $ticker->indexPrice->value() - 15 : $ticker->indexPrice->value() + 15;
            } else {
                $basePrice = $side->isLong() ? $positionPrice - 15 : $positionPrice + 15;
                $basePrice += random_int(-15, 15);
            }
        } elseif ($stopStrategy === StopCreate::SHORT_STOP) {
            $stopPriceDelta = 20 + random_int(1, 25);
            $triggerPrice = $side->isShort() ? $buyOrder->getPrice() + $stopPriceDelta : $buyOrder->getPrice() - $stopPriceDelta;
        }

        if ($basePrice) {
            if (!$ticker->isIndexAlreadyOverStop($side, $basePrice)) {
                $triggerPrice = $side === Side::Sell ? $basePrice + 1 : $basePrice - 1;
            } else {
                $description = 'because index price over stop)';
            }
        }

        // If still cannot get best $triggerPrice
        if ($stopStrategy !== StopCreate::DEFAULT && $triggerPrice === null) {
            $stopStrategy = StopCreate::DEFAULT;
        }

        if ($stopStrategy === StopCreate::DEFAULT) {
            $stopPriceDelta = StopCreate::getDefaultStrategyStopOrderDistance($volume);

            $triggerPrice = $side === Side::Sell ? $buyOrder->getPrice() + $stopPriceDelta : $buyOrder->getPrice() - $stopPriceDelta;
        }

        $this->stopService->create($position->symbol, $side, $triggerPrice, $volume, self::STOP_ORDER_TRIGGER_DELTA, $context);
    }

    /**
     * @return array{strategy: StopCreate, description: string}
     */
    private function getStopStrategy(Position $position, BuyOrder $order, Ticker $ticker): array
    {
        if (($hedge = $position->getHedge()) && $hedge->isSupportPosition($position)) {
            $hedgeStrategy = $hedge->getHedgeStrategy();
            return [
                'strategy' => $hedgeStrategy->supportPositionStopCreation, // 'strategy' => $hedge->isSupportPosition($position) ? $hedgeStrategy->supportPositionStopCreation : $hedgeStrategy->mainPositionStopCreation,
                'description' => $hedgeStrategy->description,
            ];
        }

        $delta = $position->getDeltaWithTicker($ticker);

        // only if without hedge?
        // if (($delta < 0) && (abs($delta) >= $defaultStrategyStopPriceDelta)) {return ['strategy' => StopCreate::SHORT_STOP, 'description' => 'position in loss'];}

        if ($order->isWithShortStop()) {
            return ['strategy' => StopCreate::SHORT_STOP, 'description' => 'by $order->isWithShortStop() condition'];
        }

        // @todo Нужен какой-то определятор состояния трейда
        if ($delta >= 1500) {
            return ['strategy' => StopCreate::AFTER_FIRST_STOP_UNDER_POSITION, 'description' => sprintf('delta=%.2f -> increase position size', $delta)];
        }

        if ($delta >= 500) {
            return ['strategy' => StopCreate::UNDER_POSITION, 'description' => sprintf('delta=%.2f -> to reduce added by mistake on start', $delta)];
        }

        $defaultStrategyStopPriceDelta = StopCreate::getDefaultStrategyStopOrderDistance($order->getVolume());

        // To not reduce position size by placing stop orders between position and ticker
        if ($delta > (2 * $defaultStrategyStopPriceDelta)) {
            return ['strategy' => StopCreate::UNDER_POSITION, 'description' => sprintf('delta=%.2f -> keep position size on start', $delta)];
        }

        if ($delta > $defaultStrategyStopPriceDelta) {
            return ['strategy' => StopCreate::UNDER_POSITION, 'description' => sprintf('delta=%.2f -> keep position size on start', $delta)];
        }

        return ['strategy' => StopCreate::DEFAULT, 'description' => 'by default'];
    }

    /**
     * To not make extra queries to Exchange (what can lead to a ban due to ApiRateLimitReached)
     */
    private function canBuy(Ticker $ticker): bool
    {
        $modifier = $ticker->indexPrice->value() * 0.0005;

        $refreshSeconds = 8;
        $lastCannotAffordAt = $this->lastCannotAffordAt[$ticker->symbol->name] ?? null;
        $lastCannotAffordAtPrice = $this->lastCannotAffordAtPrice[$ticker->symbol->name] ?? null;

        $canBuy =
            ($lastCannotAffordAt === null && $lastCannotAffordAtPrice === null)
            || ($lastCannotAffordAt !== null && ($this->clock->now()->getTimestamp() - $lastCannotAffordAt->getTimestamp()) >= $refreshSeconds)
            || (
                $lastCannotAffordAtPrice !== null
                && !$ticker->indexPrice->isPriceInRange(
                    PriceRange::create($lastCannotAffordAtPrice - $modifier, $lastCannotAffordAtPrice + $modifier, $ticker->symbol),
                )
            );

        if ($canBuy) {
            $this->lastCannotAffordAt[$ticker->symbol->name] = $this->lastCannotAffordAtPrice[$ticker->symbol->name] = null;
        }

        return $canBuy;
    }

    public function totalPositionLeverage(Position $position, Ticker $ticker): float
    {
        $totalBalance = $this->exchangeAccountService->getCachedTotalBalance($position->symbol);

        /**
         * @todo | use same check when make decision about transfer from spot or not
         * @see PushBuyOrdersHandler::canUseSpot
         */
        if ($position->isPositionInProfit($ticker->indexPrice)) {
            $totalBalance -= self::RESERVED_BALANCE;
        }

        return ($position->initialMargin->value() / (0.94 * $totalBalance)) * 100;
    }

    private function transferToContract(SpotBalance $spotBalance, float $amount): bool
    {
        $availableBalance = $spotBalance->available() - 0.1;

        if ($availableBalance < $amount) {
            if ($availableBalance < 0.2) {
                return false;
            }
            $amount = $availableBalance - 0.1;
        }

        try {
            $this->exchangeAccountService->interTransferFromSpotToContract($spotBalance->assetCoin, $amount);
        } catch (Throwable $e) {echo sprintf('%s::%s: %s', __FILE__, __LINE__, $e->getMessage()) . PHP_EOL;}

        return true;
    }

    public const LEVERAGE_SLEEP_RANGES = [
        92 => [-50, 80, 300],
        83 => [-40, 70, 250],
        73 => [-35, 60, 225],
        63 => [-30, 50, 170],
    ];

    public const HEDGE_LEVERAGE_SLEEP_RANGES = [
        92 => [null, null, 750],
        85 => [null, null, 625],
        75 => [null, null, 450],
        65 => [null, null, 350],
    ];

    private function getNeedIgnoreBuyReason(?Position $position, Ticker $ticker): ?string
    {
        if ($position) {
            if ($position->isSupportPosition()) {
                if ($this->hedgeService->isSupportSizeEnoughForSupportMainPosition($position->getHedge())) {
                    return '~SupportSizeEnoughForSupportMainPosition~';
                }
                return null;
            }

            $distanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
            $currentPrice = $position->isShort() ? PriceHelper::max($ticker->indexPrice, $ticker->markPrice) : PriceHelper::min($ticker->indexPrice, $ticker->markPrice);
            $totalPositionLeverage = $this->totalPositionLeverage($position, $ticker);

            $sleepRanges = $position->isMainPosition() ? self::HEDGE_LEVERAGE_SLEEP_RANGES : self::LEVERAGE_SLEEP_RANGES;
            foreach ($sleepRanges as $leverage => [$fromPnl, $toPnl, $minLiqDistancePnlPercent]) {
                $minLiqDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta($minLiqDistancePnlPercent, $position->entryPrice());
                // @todo | by now only for linear
                if ($totalPositionLeverage >= $leverage) {
                    if ($position->isMainPosition()) {
                        if ($distanceWithLiquidation < $minLiqDistance) {
                            return sprintf('l=%d | ~isMainPosition~ | $distanceWithLiquidation(%s) < $minLiqDistance (%s)', $totalPositionLeverage, $distanceWithLiquidation, $minLiqDistance);
                        }
                    } else {
                        if ($position->isPositionInLoss($currentPrice) && $distanceWithLiquidation > $minLiqDistance) {
                            break;
                        }
                        if ($position->isPositionInProfit($currentPrice) && $distanceWithLiquidation < $minLiqDistance) {
                            return sprintf('l=%d | ~isPositionInProfit~ | $distanceWithLiquidation(%s) < $minLiqDistance (%s)', $totalPositionLeverage, $distanceWithLiquidation, $minLiqDistance);
                        }
                    }

                    if ($fromPnl && $toPnl) {
                        $range = PriceRange::byPositionPnlRange($position, $fromPnl, $toPnl);
                        if ($currentPrice->isPriceInRange($range)) {
                            return sprintf('l=%d | ~isPriceInRange[%s..%s / %s]~', $totalPositionLeverage, $fromPnl, $toPnl, $range);
                        }
                    }

                    break;
                }
            }
        }

        return null;
    }

    private function noticeAboutIgnoreBuy(Position $position, BuyOrder $order, string $ignoreBuy): void
    {
//        OutputHelper::print($message = sprintf('%s: ignore buy : %s (id=b.%d)', $position->side->title(), $ignoreBuy, $order->getId()));
//        if ($this->ignoreBuyThrottlingLimiter->consume()->isAccepted()) {
//            $this->logger->critical($message);
//        }
    }

    /**
     * @param ByBitExchangeAccountService $exchangeAccountService
     * @param ByBitMarketService $marketService
     * @param ByBitOrderService $orderService
     * @param ByBitLinearExchangeCacheDecoratedService $exchangeService
     * @param ByBitLinearPositionCacheDecoratedService $positionService
     */
    public function __construct(
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
        private readonly HedgeService $hedgeService,
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly StopRepository $stopRepository,
        private readonly StopService $stopService,
        private readonly OrderCostCalculator $orderCostCalculator,

        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly MarketServiceInterface $marketService,
        private readonly OrderServiceInterface $orderService,

        private readonly MarketBuyHandler $marketBuyHandler,
        RateLimiterFactory $ignoreBuyThrottlingLimiter,
        private readonly AppSettingsProvider $settings,

        ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        ClockInterface $clock,
        LoggerInterface $appErrorLogger,
    ) {
        $this->ignoreBuyThrottlingLimiter = $ignoreBuyThrottlingLimiter->create('push_buy_orders');

        # checks
        $this->useIsLastPriceOverIndexPriceCheck = $this->settings->get(PushBuyOrderSettings::Checks_lastPriceOverIndexPriceCheckEnabled);
        $this->useIgnoreBuyCheckBasedOnTotalPositionLeverage = $this->settings->get(PushBuyOrderSettings::Checks_ignoreBuyBasedOnTotalPositionLeverageEnabled);

        parent::__construct($exchangeService, $positionService, $clock, $appErrorLogger);
    }
}
