<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
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
use App\Bot\Domain\ValueObject\Symbol;
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
use App\Infrastructure\ByBit\Service\Exception\Trade\CannotAffordOrderCost;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use App\Value\CachedValue;
use DateTimeImmutable;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function random_int;
use function sprintf;

/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder */
/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder\CornerCases */
#[AsMessageHandler]
final class PushBuyOrdersHandler extends AbstractOrdersPusher
{
    public const STOP_ORDER_TRIGGER_DELTA = 37;

    public const USE_SPOT_IF_BALANCE_GREATER_THAN = 5.5;
    public const USE_SPOT_AFTER_INDEX_PRICE_PNL_PERCENT = 100;
    public const USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT = 383;
    public const TRANSFER_TO_SPOT_PROFIT_PART_WHEN_TAKE_PROFIT = 0.05;

    private ?DateTimeImmutable $lastCannotAffordAt = null;
    private ?float $lastCannotAffordAtPrice = null;

    /** @var CachedValue[] */
    private array $balanceHotCache = [];

    private function canUseSpot(Ticker $ticker, Position $position, WalletBalance $spotBalance): bool
    {
        if ($spotBalance->availableBalance > self::USE_SPOT_IF_BALANCE_GREATER_THAN || $this->totalPositionLeverage($position) < 60) {
            return true;
        }

        $indexPnlPercent = $ticker->indexPrice->getPnlPercentFor($position);
        $minIndexPricePnlPercentToUseSpot = $position->isSupportPosition() ? self::USE_SPOT_AFTER_INDEX_PRICE_PNL_PERCENT / 2 : self::USE_SPOT_AFTER_INDEX_PRICE_PNL_PERCENT;

        return $indexPnlPercent >= $minIndexPricePnlPercentToUseSpot;
    }

    private function canTakeProfit(Position $position, Ticker $ticker): bool
    {
        $currentPnlPercent = $ticker->lastPrice->getPnlPercentFor($position);
        $minLastPricePnlPercentToTakeProfit = $position->isSupportPosition() ? self::USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT / 1.6 : self::USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT;

        return $currentPnlPercent >= $minLastPricePnlPercentToTakeProfit;
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

        $this->exchangeAccountService->interTransferFromSpotToContract($spotBalance->assetCoin, $amount);

        return true;
    }

    public function findOrdersNearTicker(Side $side, Position $position, Ticker $ticker): array
    {
        $indexPrice = $ticker->indexPrice;
        $volumeOrdering = $this->canTakeProfit($position, $ticker)
            ? 'DESC'
            : 'ASC'; // To get the cheapest orders (if can afford buy less qty)

        return $this->buyOrderRepository->findActiveInRange(
            side: $side,
            from: ($position->isShort() ? $indexPrice->value() - 15 : $indexPrice->value() - 20),
            to: ($position->isShort() ? $indexPrice->value() + 20 : $indexPrice->value() + 15),
            qbModifier: static function(QueryBuilder $qb) use ($side, $volumeOrdering) {
                QueryHelper::addOrder($qb, 'volume', $volumeOrdering);
                QueryHelper::addOrder($qb, 'price', $side->isShort() ? 'DESC' : 'ASC');
            },
        );
    }

//    public const LEVERAGE_SLEEP_RANGES = [
//        92 => [-40, 105, 1300],
//        83 => [-30, 95, 1000],
//        73 => [-20, 75, 700],
//        63 => [-10, 50, 650],
//    ];
// + min / max

    public const LEVERAGE_SLEEP_RANGES = [
        92 => [-40, 105, 1500],
        83 => [-30, 90, 1200],
        73 => [-25, 80, 900],
        63 => [-15, 60, 850],
//        51 => [-15, 30, 550],
    ];

    public function __invoke(PushBuyOrders $message): void
    {
        if ($this->marketService->isNowFundingFeesPaymentTime()) {
            return;
        }

        $side = $message->side;
        $symbol = $message->symbol;

        $ticker = $this->exchangeService->ticker($symbol);
        $position = $this->positionService->getPosition($symbol, $side);

        if ($position) {
            $priceDeltaToLiquidation = $position->priceDeltaToLiquidation($ticker);
            $currentPrice = $position->isShort() ? PriceHelper::max($ticker->indexPrice, $ticker->markPrice) : PriceHelper::min($ticker->indexPrice, $ticker->markPrice);

            foreach (self::LEVERAGE_SLEEP_RANGES as $leverage => [$fromPnl, $toPnl, $minLiqDistance]) {
                // @todo | by now only for linear
                if ($this->totalPositionLeverage($position) >= $leverage) {
                    if (!$position->isPositionInProfit($currentPrice) && $priceDeltaToLiquidation > $minLiqDistance) {
                        break;
                    }
                    if ($position->isPositionInProfit($currentPrice) && $priceDeltaToLiquidation < $minLiqDistance) {
                        return;
                    }
                    if ($currentPrice->isPriceInRange(PriceRange::byPositionPnlRange($position, $fromPnl, $toPnl))) {
                        return;
                    }

                    break;
                }
            }
        }

        if (!$position) {
            $position = new Position($side, $symbol, $ticker->indexPrice->value(), 0.05, 1000, 0, 13, 100);
        } elseif ($ticker->isLastPriceOverIndexPrice($side) && $ticker->lastPrice->deltaWith($ticker->indexPrice) >= 65) {
            // @todo test
            return;
        }

        if (!$this->canBuy($ticker)) {
            return;
        }

        $orders = $this->findOrdersNearTicker($side, $position, $ticker);

        /** @var BuyOrder $lastBuy To use, for example, after catch `CannotAffordOrderCost` exception */
        $lastBuy = null;
        try {
            $boughtOrders = [];
            foreach ($orders as $order) {
                $lastBuy = $order;
                if ($order->mustBeExecuted($ticker)) {
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
        } catch (CannotAffordOrderCost $e) {
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

            if ($this->canTakeProfit($position, $ticker)) {
                $currentPnlPercent = $ticker->lastPrice->getPnlPercentFor($position);
                $volume = VolumeHelper::forceRoundUp($e->qty / ($currentPnlPercent * 0.75 / 100));
                $this->orderService->closeByMarket($position, $volume);

                if (!$position->isSupportPosition()) {
                    $expectedProfit = PnlHelper::getPnlInUsdt($position, $ticker->lastPrice, $volume);
                    $transferToSpotAmount = $expectedProfit * self::TRANSFER_TO_SPOT_PROFIT_PART_WHEN_TAKE_PROFIT;
                    $this->exchangeAccountService->interTransferFromContractToSpot($symbol->associatedCoin(), PriceHelper::round($transferToSpotAmount, 3));
                }

                $this->buyOrderRepository->save($lastBuy);
                return;
            }

            if (
                $lastBuy->getHedgeSupportFixationsCount() < 1
                && ($hedge = $position->getHedge())
                && $hedge->isMainPosition($position)
                && $this->hedgeService->isSupportSizeEnoughForSupportMainPosition($hedge)
                && ($supportPnlPercent = $ticker->lastPrice->getPnlPercentFor($hedge->supportPosition)) > 150
            ) {
                $volume = VolumeHelper::forceRoundUp($e->qty / ($supportPnlPercent * 0.75 / 100));

                $this->orderService->closeByMarket($hedge->supportPosition, $volume);
                $lastBuy->incHedgeSupportFixationsCounter();

                $this->buyOrderRepository->save($lastBuy);
                return;
            }

            /**
             * (1) option
             *      just hard "off" / "on"
             * Then options divide into three branches:
             * (1) For support position
             *      1) just "off"
             *         => from which position profit must be taken?
             *      3) from self
             *      4) from opposite
             *
             *    For 3 and 4 options there must be some setting to choose minimum applicable PNL%
             *
             *
             *
             * Maybe decision about ability to use profit from current position also must be made based on selected option?
             */

//            if (($hedge = $position->getHedge())) {
//                if (
//                    $hedge->isSupportPosition($position) && $hedge->needIncreaseSupport()
//                    && ($ticker->lastPrice->getPnlPercentFor($hedge->mainPosition)) > 200 // mainPosition PNL%
//                ) {
//                    $this->orderService->closeByMarket($position->oppositePosition, 0.001); // $this->messageBus->dispatch(new IncreaseHedgeSupportPositionByGetProfitFromMain($e->symbol, $e->side, $e->qty));
//                } // elseif ($hedge->isMainPosition($position)) @todo придумать логику по восстановлению убытков главной позиции
//                // если $this->hedgeService->createStopIncrementalGridBySupport($hedge, $stop) (@see PushStopsHandler) окажется неработоспособной
//                // например, если на момент проверки ещё нужно было держать объём саппорта и сервис не был вызван
//            }

            $this->lastCannotAffordAtPrice = $ticker->indexPrice->value();
            $this->lastCannotAffordAt = $this->clock->now();
        }
    }

    /**
     * @throws CannotAffordOrderCost
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

    private function getCachedTotalBalance(Symbol $symbol): float
    {
        $exchangeAccountService = $this->exchangeAccountService;

        $coin = $symbol->associatedCoin();
        $balance = $this->balanceHotCache[$coin->value] ?? (
            $this->balanceHotCache[$coin->value] = new CachedValue(
                static function () use ($exchangeAccountService, $coin) {
                    $spotBalance = $exchangeAccountService->getSpotWalletBalance($coin);
                    $contractBalance = $exchangeAccountService->getContractWalletBalance($coin);

                    return $spotBalance->totalBalance + $contractBalance->totalBalance;
                },
                10000
            )
        );

        return $balance->get();
    }

    public function totalPositionLeverage(Position $position): float
    {
        $totalBalance = $this->getCachedTotalBalance($position->symbol);

        return ($position->initialMargin->value() / (0.94 * $totalBalance)) * 100;
    }

    public function __construct(
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
