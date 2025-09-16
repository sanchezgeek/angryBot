<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\MarketBuy\Exception\ChecksNotPassedException;
use App\Application\UseCase\Trading\MarketBuy\MarketBuyHandler;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\SpotBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Application\Settings\TradingSettings;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Factory\PositionFactory;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Buy\Application\Settings\PushBuyOrderSettings;
use App\Clock\ClockInterface;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\PermissionDeniedException;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Infrastructure\ByBit\Service\ByBitMarketService;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService;
use App\Infrastructure\ByBit\Service\Exception\Market\TickerNotFoundException;
use App\Infrastructure\ByBit\Service\Exception\Trade\OrderDoesNotMeetMinimumOrderValue;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\ByBit\Service\Trade\ByBitOrderService;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use App\Settings\Application\Helper\SettingsHelper;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Worker\AppContext;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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
    # @todo | canUseSpot | must be calculated "on the fly" (required balance of funds must be provided by CheckPositionIsUnderLiquidationHandler)
    public const float USE_SPOT_IF_BALANCE_GREATER_THAN = 65.5;
    public const int USE_SPOT_AFTER_INDEX_PRICE_PNL_PERCENT = 70;
    public const float TRANSFER_TO_SPOT_PROFIT_PART_WHEN_TAKE_PROFIT = 0.05;

    public const bool FIX_SUPPORT_ENABLED = false;
    public const bool FIX_MAIN_POSITION_ENABLED = false;
    public const bool FIX_SUPPORT_ONLY_FOR_BUY_OPPOSITE_ORDERS_AFTER_GOT_SL = true;

    /** "Reserved" - e.g. for avoiding liquidation */
    private const float RESERVED_BALANCE = 0;
    public const float SPOT_TRANSFER_ON_BUY_MULTIPLIER = 1.1;
    private const int LAST_CANNOT_AFFORD_RESET_INTERVAL = 20;

    private array $lastCannotAffordAt = [];
    private array $lastCannotAffordAtPrice = [];

    private readonly LimiterInterface $ignoreBuyThrottlingLimiter;

    private readonly bool $useIsLastPriceOverIndexPriceCheck;
    private readonly bool $useIgnoreBuyCheckBasedOnTotalPositionLeverage;

    private TradingCheckContext $checksContext;

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
        if (!SettingsHelper::withAlternatives(PushBuyOrderSettings::UseSpot_Enabled, $position->symbol, $position->side) !== true) {
            return false;
        }

        // @todo | check | other | reserved balance
//        if ($spotBalance->available() < self::RESERVED_BALANCE) {
//            return false;
//        }

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

//        # Skip check if "total position leverage" less than some leverage
//        # @todo | what value must be used?
//        # @todo | may be replace this check with "buyIsSafe" check (but this check probably already not passed and this scenario won't happen at all)
//        if ($this->totalPositionLeverage($position, $ticker) < 60) {
//            return true;
//        }

        $indexPnlPercent = $ticker->indexPrice->getPnlPercentFor($position);

        return $indexPnlPercent >= self::USE_SPOT_AFTER_INDEX_PRICE_PNL_PERCENT;
    }

    private function canTakeProfit(Position $position, Ticker $ticker): bool
    {
        if ($this->settings->required(TradingSettings::TakeProfit_InCaseOf_Insufficient_Balance_Enabled) !== true) {
            return false;
        }

        $currentPnlPercent = $ticker->lastPrice->getPnlPercentFor($position);
        $minLastPricePnlPercentToTakeProfit = $this->settings->required(TradingSettings::TakeProfit_InCaseOf_Insufficient_Balance_After_Position_Pnl_Percent);

        return $currentPnlPercent >= $minLastPricePnlPercentToTakeProfit;
    }

    /**
     * @return BuyOrder[]
     */
    public function findOrders(Side $side, Ticker $ticker, ?Position $openedPosition = null): array
    {
        $volumeOrdering = $openedPosition && $this->canTakeProfit($openedPosition, $ticker)
            ? 'DESC' // To get bigger orders first (to take bigger profit by their volume)
            : 'ASC'; // Buy cheapest orders first

        return $this->buyOrderRepository->findActiveForPush(
            symbol: $ticker->symbol,
            side: $side,
            currentPrice: $ticker->indexPrice->value(),
            qbModifier: static function(QueryBuilder $qb) use ($side, $volumeOrdering) {
                QueryHelper::addOrder($qb, 'volume', $volumeOrdering);
                QueryHelper::addOrder($qb, 'price', $side->isShort() ? 'DESC' : 'ASC');
            },
        );
    }

    /**
     * @todo | exceptions
     *
     * @throws UnexpectedSandboxExecutionException
     * @throws RandomException
     * @throws OrderDoesNotMeetMinimumOrderValue
     * @throws UnexpectedApiErrorException
     * @throws TickerNotFoundException
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     * @throws PermissionDeniedException
     */
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

        $orders = $this->findOrders($side, $ticker, $position);

        $ignoreBuy = null;
        if ($this->useIsLastPriceOverIndexPriceCheck && $position && $ticker->isLastPriceOverIndexPrice($side) && $last->deltaWith($index) >= 100) {
            $ignoreBuy = '~isLastPriceOverIndexPrice~ more than 100';
        } elseif ($this->useIgnoreBuyCheckBasedOnTotalPositionLeverage && $orders) {
            $ignoreBuy = $this->getNeedIgnoreBuyReason($position, $ticker);
        }

        if (!$position) {
            // @todo | sandbox | mb troubles with sandbox (inner check will not work)
            $position = PositionFactory::fakeWithNoLiquidation($symbol, $side, $index);
        }

        $this->checksContext = TradingCheckContext::withCurrentPositionState($ticker, $position);

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
                    $this->buy($order);

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
                // @todo | symbol | for all symbols
                $reopenPrice = $index->modifyByDirection($position->side, PriceMovementDirection::TO_PROFIT, $distance, zeroSafe: true);
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

            $this->lastCannotAffordAtPrice[$symbol->name()] = $index->value();
            $this->lastCannotAffordAt[$symbol->name()] = $this->clock->now();
        }
    }

    /**
     * @throws CannotAffordOrderCostException
     * @throws UnexpectedSandboxExecutionException
     * @throws OrderDoesNotMeetMinimumOrderValue
     */
    private function buy(BuyOrder $order): void
    {
        try {
            $exchangeOrderId = $this->marketBuyHandler->handle(MarketBuyEntryDto::fromBuyOrder($order), $this->checksContext);
            $order->wasPushedToExchange($exchangeOrderId);
        } catch (ChecksNotPassedException $e) {
            !$e->result->quiet && OutputHelper::failed($e->getMessage());
        } catch (ApiRateLimitReached $e) {
            $this->logWarning($e);
            $this->sleep($e->getMessage());
        } catch (UnknownByBitApiErrorException|UnexpectedApiErrorException $e) {
            $this->logCritical($e);
        } finally {
            $this->buyOrderRepository->save($order);
        }
    }

    /**
     * To not make extra queries to Exchange (what can lead to a ban due to ApiRateLimitReached)
     */
    private function canBuy(Ticker $ticker): bool
    {
        $modifier = $ticker->indexPrice->value() * 0.0005;

        $refreshSeconds = self::LAST_CANNOT_AFFORD_RESET_INTERVAL;
        $lastCannotAffordAt = $this->lastCannotAffordAt[$ticker->symbol->name()] ?? null;
        $lastCannotAffordAtPrice = $this->lastCannotAffordAtPrice[$ticker->symbol->name()] ?? null;

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
            $this->lastCannotAffordAt[$ticker->symbol->name()] = $this->lastCannotAffordAtPrice[$ticker->symbol->name()] = null;
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
        private readonly AppSettingsProviderInterface $settings,

        ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        ClockInterface $clock,
        LoggerInterface $appErrorLogger,
    ) {
        $this->ignoreBuyThrottlingLimiter = $ignoreBuyThrottlingLimiter->create('push_buy_orders');

        # checks
        $this->useIsLastPriceOverIndexPriceCheck = $this->settings->required(PushBuyOrderSettings::Checks_lastPriceOverIndexPriceCheckEnabled);
        $this->useIgnoreBuyCheckBasedOnTotalPositionLeverage = $this->settings->required(PushBuyOrderSettings::Checks_ignoreBuyBasedOnTotalPositionLeverageEnabled);

        parent::__construct($exchangeService, $positionService, $clock, $appErrorLogger);
    }
}
