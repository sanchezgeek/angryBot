<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Hedge\Hedge;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\Price;
use App\Helper\VolumeHelper;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Exception\Market\TickerNotFoundException;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\Trade\TickerOverConditionalOrderTriggerPrice;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use Doctrine\ORM\QueryBuilder as QB;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsCommonCasesTest */
/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsCornerCasesTest */
/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\TakeProfit */
#[AsMessageHandler]
final class PushStopsHandler extends AbstractOrdersPusher
{
    public const LIQUIDATION_WARNING_DELTA = 50;
    public const LIQUIDATION_CRITICAL_DELTA = 35;

    public const PRICE_MODIFIER_IF_CURRENT_PRICE_OVER_STOP = 15;
    public const TD_MODIFIER_IF_CURRENT_PRICE_OVER_STOP = 7;

    private const SL_DEFAULT_TRIGGER_DELTA = 25;
    private const SL_SUPPORT_FROM_MAIN_HEDGE_POSITION_TRIGGER_DELTA = 5;

    public const SHORT_BUY_ORDER_OPPOSITE_PRICE_DISTANCE = 78;
    public const LONG_BUY_ORDER_OPPOSITE_PRICE_DISTANCE = 141;

    public function __invoke(PushStops $message): void
    {
        $side = $message->side; $symbol = $message->symbol;

        if (!($position = $this->positionService->getPosition($symbol, $side))) {
            return;
        }

        $stops = $this->findStops($side, $symbol);
        $ticker = $this->exchangeService->ticker($symbol); // If ticker changed while get stops
        $deltaToLiquidation = $position->priceDeltaToLiquidation($ticker);

        if ($deltaToLiquidation <= self::LIQUIDATION_WARNING_DELTA) {
            $triggerBy = TriggerBy::MarkPrice;  $currentPrice = $ticker->markPrice;
        } else {
            $triggerBy = TriggerBy::IndexPrice; $currentPrice = $ticker->indexPrice;
        }

        $positionService = $this->positionService; $orderService = $this->orderService;
        foreach ($stops as $stop) {
            ### TP
            if ($stop->isTakeProfitOrder()) {
                if ($ticker->lastPrice->isPriceOverTakeProfit($side, $stop->getPrice())) {
                    $this->pushStopToExchange($ticker, $stop, static fn() => $orderService->closeByMarket($position, $stop->getVolume()));
                }
                continue;
            }

            ### Regular
            $td = $this->getStopTriggerDelta($stop);
            $stopPrice = $stop->getPrice();

            if (($currentPriceOverStop = $currentPrice->isPriceOverStop($side, $stopPrice)) || (abs($stopPrice - $currentPrice->value()) <= $td)) {
                $callback = null;
                if ($stop->isCloseByMarketContextSet()) {
                    $exchangeAccountService = $this->exchangeAccountService;
                    $callback = static function () use ($orderService, $position, $stop, $exchangeAccountService) {
                        $orderId = $orderService->closeByMarket($position, $stop->getVolume());

                        $expectedLoss = $stop->getPnlUsd($position);
                        $transferAmount = -$expectedLoss;
                        $exchangeAccountService->interTransferFromSpotToContract($position->symbol->associatedCoin(), PriceHelper::round($transferAmount, 3));

                        return $orderId;
                    };
                } elseif ($currentPriceOverStop) {
                    if ($deltaToLiquidation <= self::LIQUIDATION_CRITICAL_DELTA) {
                        $callback = static fn() => $orderService->closeByMarket($position, $stop->getVolume());
                    } else {
                        $newPrice = $side->isShort() ? $currentPrice->value() + self::PRICE_MODIFIER_IF_CURRENT_PRICE_OVER_STOP : $currentPrice->value() - self::PRICE_MODIFIER_IF_CURRENT_PRICE_OVER_STOP;
                        $stop->setPrice($newPrice)->setTriggerDelta($td + self::TD_MODIFIER_IF_CURRENT_PRICE_OVER_STOP);
                    }
                }

                $this->pushStopToExchange($ticker, $stop, $callback ?: static function() use ($positionService, $orderService, $position, $stop, $triggerBy) {
                    try {
                        return $positionService->addConditionalStop($position, $stop->getPrice(), $stop->getVolume(), $triggerBy);
                    } catch (TickerOverConditionalOrderTriggerPrice $e) {
                        return $orderService->closeByMarket($position, $stop->getVolume());
                    }
                });

                if ($stop->getExchangeOrderId()) {
                    if ($stop->isWithOppositeOrder()) {
                        $this->createOpposite($position, $stop, $ticker);
                    }
//                    $this->events->dispatch(new StopPushedToExchange($stop));
                }
            }
        }
    }

    private function pushStopToExchange(Ticker $ticker, Stop $stop, callable $pushStopCallback): void
    {
        try {
            $stopOrderId = $pushStopCallback();
            $stop->setExchangeOrderId($stopOrderId);
        } catch (ApiRateLimitReached $e) {
            $this->logWarning($e);
            $this->sleep($e->getMessage());
        } catch (MaxActiveCondOrdersQntReached $e) {
            // @todo | currentPriceWithLiquidationPriceDifference->delta() + isCurrentPriceOverStopPrice
            $this->messageBus->dispatch(TryReleaseActiveOrders::forStop($ticker->symbol, $stop));
        } catch (UnknownByBitApiErrorException|UnexpectedApiErrorException $e) {
            $this->logCritical($e);
        } finally {
            $this->repository->save($stop);
        }
    }

    /**
     * @return array<array{id: int, volume: float, price: float}>
     */
    private function createOpposite(Position $position, Stop $stop, Ticker $ticker): array
    {
        // @todo Костыль
        if ($position->size > 2) {
            return [];
        }

        $side = $stop->getPositionSide();
        $price = $stop->getPrice(); // $price = $stop->getOriginalPrice() ?? $stop->getPrice();
        $distance = $this->getBuyOrderOppositePriceDistance($position->side);

        $triggerPrice = $side === Side::Sell ? $price - $distance : $price + $distance;
        $volume = $stop->getVolume() >= 0.006 ? VolumeHelper::round($stop->getVolume() / 3) : $stop->getVolume();

//        $isHedge = ($oppositePosition = $this->positionService->getOppositePosition($position)) !== null;
        $isHedge = false;
        if ($isHedge) {
            $hedge = Hedge::create($position, $oppositePosition);
            // If this is support position, we need to make sure that we can afford opposite buy after stop (which was added, for example, by mistake)
            if (
                $hedge->isSupportPosition($position)
                && $hedge->needIncreaseSupport()
            ) {
                $vol = VolumeHelper::round($volume / 3);

                if ($vol > 0.005) {
                    $vol = 0.005;
                }

                // @todo Всё это лучше вынести в настройки
                // С человекопонятными названиями

                $this->stopService->create(
                    $oppositePosition->side,
                    $oppositePosition->side === Side::Sell ? ($triggerPrice - 3) : ($triggerPrice + 3),
                    $vol,
                    self::SL_SUPPORT_FROM_MAIN_HEDGE_POSITION_TRIGGER_DELTA,
                    ['asSupportFromMainHedgePosition' => true, 'createdWhen' => 'tryGetHelpFromHandler'],
                );
            } elseif (
                $hedge->isMainPosition($position)
                && $ticker->isIndexAlreadyOverStop($position->side, $position->entryPrice) // MainPosition now in loss
                && !$hedge->needKeepSupportSize()
            ) {
                // @todo Need async job instead (to check $hedge->needKeepSupportSize() in future, if now still need keep support size)
                // Or it can be some problems at runtime...Need async job
                $this->hedgeService->createStopIncrementalGridBySupport($hedge, $stop);
            }
            // @todo Придумать нормульную логику (доделать проверку баланса и необходимость в фиксации main-позиции?)
            // Пока что добавил отлов CannotAffordOrderCost в PushBuyOrdersHandler при попытке купить
        }

        $context = ['onlyAfterExchangeOrderExecuted' => $stop->getExchangeOrderId()];

        $orders = [
            ['volume' => $volume, 'price' => $triggerPrice]
        ];

        if ($stop->getVolume() >= 0.006) {
            $orders[] = [
                'volume' => VolumeHelper::round($stop->getVolume() / 4.5),
                'price' => PriceHelper::round(
                    $side === Side::Sell ? $triggerPrice - $distance / 3.8 : $triggerPrice + $distance / 3.8
                ),
            ];
            $orders[] = [
                'volume' => VolumeHelper::round($stop->getVolume() / 3.5),
                'price' => PriceHelper::round(
                    $side === Side::Sell ? $triggerPrice - $distance / 2 : $triggerPrice + $distance / 2
                ),
            ];
        }

        foreach ($orders as $order) {
            $this->createBuyOrderHandler->handle(
                new CreateBuyOrderEntryDto($side, $order['volume'], $order['price'], $context)
            );
        }

        return $orders;
    }

    private function getBuyOrderOppositePriceDistance(Side $side): float
    {
        return $side->isLong() ? self::LONG_BUY_ORDER_OPPOSITE_PRICE_DISTANCE : self::SHORT_BUY_ORDER_OPPOSITE_PRICE_DISTANCE;
    }

    private function getStopTriggerDelta(Stop $stop): float
    {
        if ($stop->isCloseByMarketContextSet()) {
            return 0.3;
        }

        return $stop->getTriggerDelta() ?: self::SL_DEFAULT_TRIGGER_DELTA;
    }

    /**
     * @param Side $side
     * @param Symbol $symbol
     * @return Stop[]
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws TickerNotFoundException
     */
    private function findStops(Side $side, Symbol $symbol): array
    {
        return $this->repository->findActive(
            side: $side,
            nearTicker: $this->exchangeService->ticker($symbol),
            qbModifier: static fn(QB $qb) => QueryHelper::addOrder($qb, 'price', $side->isShort() ? 'ASC' : 'DESC')
        );
    }

    public function __construct(
        private readonly HedgeService $hedgeService,
        private readonly StopRepository $repository,
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
        private readonly StopService $stopService,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly MessageBusInterface $messageBus,
        OrderServiceInterface $orderService,
        ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        LoggerInterface $logger,
        ClockInterface $clock,
    ) {
        parent::__construct($orderService, $exchangeService, $positionService, $clock, $logger);
    }
}
