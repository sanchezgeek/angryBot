<?php

declare(strict_types=1);

namespace App\Bot\Application\MessageHandler;

use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Message\FindPositionBuyOrdersToAdd;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Application\Service\Strategy\HedgeOppositeStopCreate;
use App\Bot\Application\Service\Strategy\SelectedStrategy;
use App\Bot\Domain\BuyOrderRepository;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Bot\Application\Exception\MaxActiveCondOrdersQntReached;
use App\Bot\Service\Stop\StopService;
use App\Clock\ClockInterface;
use App\Trait\LoggerTrait;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class FindPositionBuyOrdersToAddHandler extends AbstractPositionNearestOrdersChecker
{
    private const DEFAULT_TRIGGER_DELTA = 1;
    private const STOP_ORDER_TRIGGER_DELTA = 3;
    private const REGULAR_ORDER_STOP_DISTANCE = 45;
    private const ADDITION_ORDER_STOP_DISTANCE = 70;

//    private const HEDGE_POSITION_REGULAR__ORDER_STOP_DISTANCE = 45;
//    private const HEDGE_POSITION_ADDITION_ORDER_STOP_DISTANCE = 70;

    private ?Ticker $lastTicker = null;

    public function __construct(
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly StopRepository $stopRepository,
        private readonly StopService $stopService,
        private readonly MessageBusInterface $messageBus,

        private readonly HedgeService $hedgeService,
        private readonly SelectedStrategy $selectedStrategy,

        PositionServiceInterface $positionService,
        LoggerInterface $logger,
        ClockInterface $clock,
    ) {
        parent::__construct($positionService, $clock, $logger);
    }

    public function __invoke(FindPositionBuyOrdersToAdd $message): void
    {
        $positionData = $this->getPositionData($message->symbol, $message->side);
        if (!$positionData->isPositionOpened()) {
            return;
        }

        $orders = $this->buyOrderRepository->findActive($positionData->position->side, $this->lastTicker);
        $ticker = $this->positionService->getTickerInfo($message->symbol);

        foreach ($orders as $order) {
            $delta = $order->getTriggerDelta() ?: self::DEFAULT_TRIGGER_DELTA;
            if (abs($order->getPrice() - $ticker->indexPrice) <= $delta) {
                $this->addBuyOrder($positionData->position, $ticker, $order);
            } elseif ($ticker->isIndexPriceAlreadyOverBuyOrderPrice($positionData->position->side, $order->getPrice())) {
                $price = $order->getPositionSide() === Side::Sell ? $ticker->indexPrice - 10 : $ticker->indexPrice + 10;
                $order->setPrice($price);

                $this->addBuyOrder($positionData->position, $ticker, $order);
            }
        }

        $this->lastTicker = $ticker;

        $this->info(\sprintf('%s: %.2f', $message->symbol->value, $ticker->indexPrice));
    }

    private function addBuyOrder(Position $position, Ticker $ticker, BuyOrder $buyOrder): void
    {
        try {
            $exchangeOrderId = $this->positionService->addBuyOrder($position, $ticker, $buyOrder->getPrice(), $buyOrder->getVolume());

            if ($exchangeOrderId) {
                $buyOrder->setExchangeOrderId($exchangeOrderId);

                if ($buyOrder->getVolume() <= 0.005) {
                    $this->buyOrderRepository->remove($buyOrder);
                } else {
                    $this->buyOrderRepository->save($buyOrder);
                }

                $stopData = $this->createOpposite($position, $ticker, $buyOrder);

                $this->info(
                    \sprintf(
                        '+++ BuyOrder %s|%.3f|%.2f pushed to exchange (stop: $%.2f)',
                        $position->getCaption(),
                        $buyOrder->getVolume(),
                        $buyOrder->getPrice(),
                        $stopData['triggerPrice']
                    ),
                    ['exchange.orderId' => $exchangeOrderId, '`stop`' => $stopData],
                );
            }
        } catch (MaxActiveCondOrdersQntReached $e) {
            $this->messageBus->dispatch(
                TryReleaseActiveOrders::forBuyOrder($ticker->symbol, $buyOrder)
            );
        }
    }

    /**
     * @return array{id: int, triggerPrice: float}
     */
    private function createOpposite(Position $position, Ticker $ticker, BuyOrder $buyOrder): array
    {
        $triggerPrice = null;
        $positionSide = $position->side;

        $oppositePriceDelta = $buyOrder->getVolume() >= 0.005
            ? self::REGULAR_ORDER_STOP_DISTANCE
            : self::ADDITION_ORDER_STOP_DISTANCE;

        $isHedge = ($oppositePosition = $this->getOppositePosition($position)) !== null;
        if ($isHedge) {
            $basePrice = null;

            $hedge = $this->hedgeService->getPositionsHedge($position, $oppositePosition);
            $stopStrategy = $hedge->isSupportPosition($position) ? $this->selectedStrategy->hedgeSupportPositionOppositeStopCreation : $this->selectedStrategy->hedgeMainPositionOppositeStopCreation;

            if ($stopStrategy === HedgeOppositeStopCreate::AFTER_FIRST_POSITION_STOP) {
                $firstPositionStop = $this->stopRepository->findActive(
                    side: $positionSide,
                    qbModifier: static fn (QueryBuilder $qb) => $qb->addOrderBy(new OrderBy($qb->getRootAliases()[0] . '.price', $position->side === Side::Sell ? 'ASC' : 'DESC'))->setMaxResults(1)
                );

                if ($firstPositionStop = $firstPositionStop[0] ?? null) {
                    $basePrice = $firstPositionStop->getPrice();
                }
            } elseif ($stopStrategy === HedgeOppositeStopCreate::UNDER_POSITION) {
                $positionPrice = \ceil($position->entryPrice);
                $basePrice = $ticker->isIndexPriceAlreadyOverStopPrice($positionSide, $positionPrice) ? $ticker->indexPrice : $positionPrice; // tmp

                $basePrice = $positionSide === Side::Sell ? $basePrice - 10 : $basePrice + 10;
            }

            if ($basePrice) {
                $triggerPrice = $positionSide === Side::Sell ? $basePrice + 1 : $basePrice - 1;
            }
        }

        // If still cannot calc $triggerPrice
        if ($triggerPrice === null) {
            $basePrice = $buyOrder->getOriginalPrice() ?? $buyOrder->getPrice();

            $triggerPrice = $positionSide === Side::Sell ? $basePrice + $oppositePriceDelta : $basePrice - $oppositePriceDelta;
        }

        $stopId = $this->stopService->create(
            $positionSide,
            $triggerPrice,
            $buyOrder->getVolume(),
            self::STOP_ORDER_TRIGGER_DELTA,
//            ['onlyAfterExchangeOrderExecuted' => $buyOrder->getExchangeOrderId()], On ByBit Buy happens immediately
        );

        return ['id' => $stopId, 'triggerPrice' => $triggerPrice];
    }
}
