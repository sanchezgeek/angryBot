<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
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
use App\Clock\ClockInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Helper\VolumeHelper;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\Trade\TickerOverConditionalOrderTriggerPrice;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use Doctrine\ORM\QueryBuilder as QB;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/** @see PushStopsTest */
#[AsMessageHandler]
final class PushStopsHandler extends AbstractOrdersPusher
{
    public const STOP_PRICE_MODIFIER_IF_INDEX_OVER_STOP = 15;
    public const TRIGGER_DELTA_MODIFIER_IF_INDEX_OVER_STOP = 7;

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

        $stops = $this->repository->findActive(
            side: $side,
            nearTicker: $this->exchangeService->ticker($symbol),
            qbModifier: static fn (QB $qb) => QueryHelper::addOrder($qb, 'price', $side->isShort() ? 'ASC' : 'DESC')
        );
        $ticker = $this->exchangeService->ticker($symbol); // For case if ticker changed while DB query

        foreach ($stops as $stop) {
            $td = $stop->getTriggerDelta() ?: self::SL_DEFAULT_TRIGGER_DELTA;
            $price = $stop->getPrice();

            if (
                ($indexAlreadyOverStop = $ticker->isIndexAlreadyOverStop($side, $price))
                || (abs($price - $ticker->indexPrice) <= ($this->slForcedTriggerDelta ?: $td))
            ) {
                if ($indexAlreadyOverStop) {
                    $newPrice = $side->isShort()
                        ? $ticker->indexPrice + self::STOP_PRICE_MODIFIER_IF_INDEX_OVER_STOP
                        : $ticker->indexPrice - self::STOP_PRICE_MODIFIER_IF_INDEX_OVER_STOP
                    ;
                    $stop->setPrice($newPrice)->setTriggerDelta($td + self::TRIGGER_DELTA_MODIFIER_IF_INDEX_OVER_STOP);
                }

                $this->addStop($position, $ticker, $stop);

                if ($stop->getExchangeOrderId() && $stop->isWithOppositeOrder()) {
                    $this->createOpposite($position, $stop, $ticker);
                }
            }
        }
    }

    private function addStop(Position $position, Ticker $ticker, Stop $stop): void
    {
        $qty = $stop->getVolume();

        try {
            try {
                $stopOrderId = $this->positionService->addStop($position, $ticker, $stop->getPrice(), $qty);
            } catch (TickerOverConditionalOrderTriggerPrice $e) {
                $stopOrderId = $this->orderService->closeByMarket($position, $qty);
            }
            // $this->events->dispatch(new StopPushedToExchange($stop));
            $stop->setExchangeOrderId($stopOrderId);
        } catch (ApiRateLimitReached $e) {
            $this->logWarning($e);
            $this->sleep($e->getMessage());
        } catch (MaxActiveCondOrdersQntReached $e) {
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

    public function __construct(
        private readonly HedgeService $hedgeService,
        private readonly StopRepository $repository,
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
        private readonly StopService $stopService,
        private readonly MessageBusInterface $messageBus,
        OrderServiceInterface $orderService,
        ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        LoggerInterface $logger,
        ClockInterface $clock,

        private readonly float $slForcedTriggerDelta
    ) {
        parent::__construct($orderService, $exchangeService, $positionService, $clock, $logger);
    }
}
