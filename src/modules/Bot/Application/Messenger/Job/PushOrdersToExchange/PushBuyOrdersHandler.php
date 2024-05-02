<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Strategy\StopCreate;
use App\Bot\Domain\Ticker;
use App\Clock\ClockInterface;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostHelper;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\VolumeHelper;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Infrastructure\ByBit\Service\ByBitMarketService;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\ByBit\Service\Trade\ByBitOrderService;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use DateTimeImmutable;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

use function abs;
use function max;
use function min;
use function random_int;
use function sprintf;

/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder\PushBuyOrdersCommonCasesTest */
/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder\CornerCases */
#[AsMessageHandler]
final class PushBuyOrdersHandler extends AbstractOrdersPusher
{
    public const STOP_ORDER_TRIGGER_DELTA = 37;

    public const USE_SPOT_IF_BALANCE_GREATER_THAN = 55.5;
    public const USE_SPOT_AFTER_INDEX_PRICE_PNL_PERCENT = 70;
    public const USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT = 234;
    public const TRANSFER_TO_SPOT_PROFIT_PART_WHEN_TAKE_PROFIT = 0.05;

    public const FIX_SUPPORT_ENABLED = false;
    public const FIX_MAIN_POSITION_ENABLED = false;
    public const FIX_SUPPORT_ONLY_FOR_BUY_OPPOSITE_ORDERS_AFTER_GOT_SL = true;

    private const RESERVED_BALANCE = 0;

    private ?DateTimeImmutable $lastCannotAffordAt = null;
    private ?float $lastCannotAffordAtPrice = null;

    private function canUseSpot(Ticker $ticker, Position $position, WalletBalance $spotBalance): bool
    {
        if ($position->getHedge()?->isMainPosition($position) && !$position->isPositionInProfit($ticker->indexPrice)) {
            return true;
        }

        if ($spotBalance->availableBalance > self::USE_SPOT_IF_BALANCE_GREATER_THAN || $this->totalPositionLeverage($position, $ticker) < 60) {
            return true;
        }

        $indexPnlPercent = $ticker->indexPrice->getPnlPercentFor($position);

        return $indexPnlPercent >= self::USE_SPOT_AFTER_INDEX_PRICE_PNL_PERCENT;
    }

    private function canTakeProfit(Position $position, Ticker $ticker): bool
    {
        $currentPnlPercent = $ticker->lastPrice->getPnlPercentFor($position);
        $minLastPricePnlPercentToTakeProfit = $position->isSupportPosition() ? self::USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT / 1.3 : self::USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT;

        return $currentPnlPercent >= $minLastPricePnlPercentToTakeProfit;
    }

    /**
     * @return BuyOrder[]
     */
    public function findOrdersNearTicker(Side $side, Position $position, Ticker $ticker): array
    {
        $volumeOrdering = $this->canTakeProfit($position, $ticker) ? 'DESC' : 'ASC'; // To get the cheapest orders (if can afford buy less qty)

        return $this->buyOrderRepository->findActiveInRange(
            side: $side,
            from: ($position->isShort() ? $ticker->indexPrice->value() - 15 : $ticker->indexPrice->value() - 20),
            to: ($position->isShort() ? $ticker->indexPrice->value() + 20 : $ticker->indexPrice->value() + 15),
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

        $position = $this->positionService->getPosition($symbol, $side);
        $ignoreBuy = $this->isNeedIgnoreBuy($position, $ticker);

        if (!$position) {
            $position = new Position($side, $symbol, $ticker->indexPrice->value(), 0.05, 1000, 0, 13, 100);
        } elseif ($ticker->isLastPriceOverIndexPrice($side) && $ticker->lastPrice->deltaWith($ticker->indexPrice) >= 65) {
            $ignoreBuy = true;
        }

        $orders = $this->findOrdersNearTicker($side, $position, $ticker);

        /** @var BuyOrder $lastBuy To use, for example, after catch `CannotAffordOrderCost` exception */
        $lastBuy = null;
        try {
            $boughtOrders = [];
            foreach ($orders as $order) {
                if ($ignoreBuy && !$order->isForceBuyOrder()) {
                    continue;
                }

                if ($order->mustBeExecuted($ticker)) {
                    $lastBuy = $order;
                    $this->buy($position, $ticker, $order);

                     if ($order->getExchangeOrderId()) {
                         $boughtOrders[] = new ExchangeOrder($symbol, $order->getVolume(), $ticker->lastPrice);
                     }
                }
            }

            if ($boughtOrders) {
                $spentCost = 0;
                foreach ($boughtOrders as $boughtOrder) {
                    $spentCost += $this->orderCostHelper->getOrderBuyCost($boughtOrder, $position->leverage)->value();
                }

                if ($spentCost > 0) {
                    $multiplier = $position->isSupportPosition() ? 0.5 : 1.25;
                    $amount = $spentCost * $multiplier;
                    $spotBalance = $this->exchangeAccountService->getSpotWalletBalance($symbol->associatedCoin());
                    if ($this->canUseSpot($ticker, $position, $spotBalance)) {
                        $this->transferToContract($spotBalance, $amount);
                    }
                }
            }
        } catch (CannotAffordOrderCostException $e) {
            $spotBalance = $this->exchangeAccountService->getSpotWalletBalance($symbol->associatedCoin());
            if ($lastBuy->getSuccessSpotTransfersCount() < 1 && $this->canUseSpot($ticker, $position, $spotBalance)) {
                $orderCost = $this->orderCostHelper->getOrderBuyCost(new ExchangeOrder($symbol, $e->qty, $ticker->lastPrice), $position->leverage)->value();
                $amount = $orderCost * 1.1; // $amount = $position->getDeltaWithTicker($ticker) < 200 ? self::SHORT_DISTANCE_TRANSFER_AMOUNT : self::LONG_DISTANCE_TRANSFER_AMOUNT;
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
                    && $hedge->mainPosition->isPositionInLoss($ticker->lastPrice)
                )
            ) {
                $currentPnlPercent = $ticker->lastPrice->getPnlPercentFor($position);
                // @todo | move to some service | DRY (below)
                $volumeClosed = VolumeHelper::forceRoundUp($e->qty / ($currentPnlPercent * 0.5 / 100));
                $this->orderService->closeByMarket($position, $volumeClosed);

                if (!$position->isSupportPosition()) {
                    $expectedProfit = PnlHelper::getPnlInUsdt($position, $ticker->lastPrice, $volumeClosed);
                    $transferToSpotAmount = $expectedProfit * self::TRANSFER_TO_SPOT_PROFIT_PART_WHEN_TAKE_PROFIT;
                    $this->exchangeAccountService->interTransferFromContractToSpot($symbol->associatedCoin(), PriceHelper::round($transferToSpotAmount, 3));
                }

                // reopen closed volume on further movement
                $distance = 100; if ($_ENV['APP_ENV'] !== 'test') $distance += random_int(-20, 35);
                $reopenPrice = $position->isShort() ? $ticker->indexPrice->sub($distance) : $ticker->indexPrice->add($distance);
                $this->createBuyOrderHandler->handle(
                    new CreateBuyOrderEntryDto($side, $volumeClosed, $reopenPrice->value(), [BuyOrder::ONLY_IF_HAS_BALANCE_AVAILABLE_CONTEXT => true])
                );

                return;
            }

            # mainPosition BuyOrder => grab profit from Support
            $priceToCalcLiqDiff = $position->isShort() ? max($position->entryPrice, $ticker->markPrice->value()) : min($position->entryPrice, $ticker->markPrice->value());
            if (self::FIX_SUPPORT_ENABLED && ($hedge = $position->getHedge())?->isMainPosition($position)
                && $lastBuy->getHedgeSupportFixationsCount() < 1
                && (
                    $lastBuy->isForceBuyOrder()
                    || (!self::FIX_SUPPORT_ONLY_FOR_BUY_OPPOSITE_ORDERS_AFTER_GOT_SL || $lastBuy->isOppositeBuyOrderAfterStopLoss())
                )
                && ($mainPositionPnlPercent = $ticker->lastPrice->getPnlPercentFor($hedge->mainPosition)) < 30 # to prevent use supportPosition profit through the way to mainPosition :)
                && ($supportPnlPercent = $ticker->lastPrice->getPnlPercentFor($hedge->supportPosition)) > 228.228
                && (
                    $lastBuy->isForceBuyOrder()
                    || abs($priceToCalcLiqDiff - $position->liquidationPrice) > 2500               # position liquidation too far
                    || $this->hedgeService->isSupportSizeEnoughForSupportMainPosition($hedge)   # or support size enough
                )
            ) {
                $volume = VolumeHelper::forceRoundUp($e->qty / ($supportPnlPercent * 0.75 / 100));

                $this->orderService->closeByMarket($hedge->supportPosition, $volume);
                $lastBuy->incHedgeSupportFixationsCounter();

                $this->buyOrderRepository->save($lastBuy);
                return;
            }

            # support BuyOrder => grab profit from MainPosition
            if (self::FIX_MAIN_POSITION_ENABLED && ($hedge = $position->getHedge())?->isSupportPosition($position)
                && $lastBuy->getHedgeSupportFixationsCount() < 1
                && ($mainPositionPnlPercent = $ticker->lastPrice->getPnlPercentFor($hedge->mainPosition)) > 152.228
                && (
                    $lastBuy->isForceBuyOrder()
                    || !$this->hedgeService->isSupportSizeEnoughForSupportMainPosition($hedge)
                )
            ) {
                $volume = VolumeHelper::forceRoundUp($e->qty / ($mainPositionPnlPercent * 0.75 / 100));

                $this->orderService->closeByMarket($hedge->mainPosition, $volume);
                $lastBuy->incHedgeSupportFixationsCounter();

                $this->buyOrderRepository->save($lastBuy);
                return;
            }

            $this->lastCannotAffordAtPrice = $ticker->indexPrice->value();
            $this->lastCannotAffordAt = $this->clock->now();
        }
    }

    /**
     * @throws CannotAffordOrderCostException
     */
    private function buy(Position $position, Ticker $ticker, BuyOrder $order): void
    {
        try {
            $exchangeOrderId = $this->orderService->marketBuy($position->symbol, $position->side, $order->getVolume());
            $order->setExchangeOrderId($exchangeOrderId);
//            $this->events->dispatch(new BuyOrderPushedToExchange($order));

            if ($order->isWithOppositeOrder()) {
                $this->createStop($position, $ticker, $order);
            }

            if ($order->getVolume() <= 0.005) {
                $this->buyOrderRepository->remove($order);
                unset($order);
            }
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
            $this->stopService->create($side, $triggerPrice, $volume, self::STOP_ORDER_TRIGGER_DELTA, $context);
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

        $this->stopService->create($side, $triggerPrice, $volume, self::STOP_ORDER_TRIGGER_DELTA, $context);
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
        $refreshSeconds = 8;
        $canBuy =
            ($this->lastCannotAffordAt === null && $this->lastCannotAffordAtPrice === null)
            || ($this->lastCannotAffordAt !== null && ($this->clock->now()->getTimestamp() - $this->lastCannotAffordAt->getTimestamp()) >= $refreshSeconds)
            || (
                $this->lastCannotAffordAtPrice !== null
                && !$ticker->indexPrice->isPriceInRange(
                    PriceRange::create($this->lastCannotAffordAtPrice - 15, $this->lastCannotAffordAtPrice + 15),
                )
            );

        if ($canBuy) {
            $this->lastCannotAffordAt = $this->lastCannotAffordAtPrice = null;
        }

        return $canBuy;
    }

    public function totalPositionLeverage(Position $position, Ticker $ticker): float
    {
        $totalBalance = $this->exchangeAccountService->getCachedTotalBalance($position->symbol);

        if ($ticker->isIndexAlreadyOverBuyOrder($position->side, $position->entryPrice)) {
            $totalBalance -= self::RESERVED_BALANCE;
        }

        return ($position->initialMargin->value() / (0.94 * $totalBalance)) * 100;
    }

    private function transferToContract(WalletBalance $spotBalance, float $amount): bool
    {
        $availableBalance = $spotBalance->availableBalance - 0.1;

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
        92 => [-50, 80, 1250],
        83 => [-40, 70, 1000],
        73 => [-35, 60, 900],
        63 => [-30, 50, 750],
    ];

    public const HEDGE_LEVERAGE_SLEEP_RANGES = [
        92 => [-40, 70, 3500],
        85 => [-25, 55, 3000],
        75 => [-20, 40, 2000],
        65 => [-5, 5, 1500],
    ];

    private function isNeedIgnoreBuy(?Position $position, Ticker $ticker): bool
    {
        if ($position) {
            $hedge = $position->getHedge();
            if ($hedge?->isSupportPosition($position) && $this->hedgeService->isSupportSizeEnoughForSupportMainPosition($hedge)) {
                return true;
            }

            $priceDeltaToLiquidation = $position->priceDeltaToLiquidation($ticker);
            $currentPrice = $position->isShort() ? PriceHelper::max($ticker->indexPrice, $ticker->markPrice) : PriceHelper::min($ticker->indexPrice, $ticker->markPrice);
            $totalPositionLeverage = $this->totalPositionLeverage($position, $ticker);

            $sleepRanges = $hedge?->isMainPosition($position) ? self::HEDGE_LEVERAGE_SLEEP_RANGES : self::LEVERAGE_SLEEP_RANGES;
            foreach ($sleepRanges as $leverage => [$fromPnl, $toPnl, $minLiqDistance]) {
                // @todo | by now only for linear
                if ($totalPositionLeverage >= $leverage) {
                    if ($position->isPositionInLoss($currentPrice) && $priceDeltaToLiquidation > $minLiqDistance) {
                        break;
                    }
                    if ($position->isPositionInProfit($currentPrice) && $priceDeltaToLiquidation < $minLiqDistance) {
                        return true;
                    }
                    if ($currentPrice->isPriceInRange(PriceRange::byPositionPnlRange($position, $fromPnl, $toPnl))) {
                        return true;
                    }

                    break;
                }
            }
        }

        return false;
    }

    /**
     * @param CreateBuyOrderHandler $createBuyOrderHandler
     * @param HedgeService $hedgeService
     * @param BuyOrderRepository $buyOrderRepository
     * @param StopRepository $stopRepository
     * @param StopService $stopService
     * @param OrderCostHelper $orderCostHelper
     * @param ByBitExchangeAccountService $exchangeAccountService
     * @param ByBitMarketService $marketService
     * @param ByBitOrderService $orderService
     * @param ByBitLinearExchangeCacheDecoratedService $exchangeService
     * @param ByBitLinearPositionCacheDecoratedService $positionService
     * @param ClockInterface $clock
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
        private readonly HedgeService $hedgeService,
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly StopRepository $stopRepository,
        private readonly StopService $stopService,
        private readonly OrderCostHelper $orderCostHelper,

        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly MarketServiceInterface $marketService,
        private readonly OrderServiceInterface $orderService,

        ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        ClockInterface $clock,
        LoggerInterface $logger,
    ) {
        parent::__construct($exchangeService, $positionService, $clock, $logger);
    }
}
