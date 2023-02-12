<?php

declare(strict_types=1);

namespace App\Bot\Application\MessageHandler;

use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Message\FindPositionBuyOrdersToAdd;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\BuyOrderRepository;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Bot\Application\Exception\MaxActiveCondOrdersQntReached;
use App\Bot\Service\Stop\StopService;
use App\Clock\ClockInterface;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class FindPositionBuyOrdersToAddHandler
{
    use LoggerTrait;

    private const DEFAULT_TRIGGER_DELTA = 1;
    private const STOP_ORDER_TRIGGER_DELTA = 3;
    private const REGULAR_ORDER_STOP_DISTANCE = 45;
    private const ADDITION_ORDER_STOP_DISTANCE = 70;

//    private const HEDGE_POSITION_REGULAR__ORDER_STOP_DISTANCE = 45;
//    private const HEDGE_POSITION_ADDITION_ORDER_STOP_DISTANCE = 70;

    private ?Ticker $lastTicker = null;

    /**
     * @var PositionData[]
     */
    private array $positionsData = [];

    public function __construct(
        private readonly PositionServiceInterface $positionService,
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly StopService $stopService,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockInterface $clock,
        LoggerInterface $logger,
    ) {
        $this->logger = $logger;
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
            } elseif ($this->isCurrentIndexPriceAlreadyOverOrderPrice($ticker, $order)) {
                $price = $order->getPositionSide() === Side::Sell ? $ticker->indexPrice - 10 : $ticker->indexPrice + 10;
                $order->setPrice($price);

                $this->addBuyOrder($positionData->position, $ticker, $order);
            }
        }

        $this->lastTicker = $ticker;

        $this->info(\sprintf('%s: %.2f', $message->symbol->value, $ticker->indexPrice));
    }

    private function isCurrentIndexPriceAlreadyOverOrderPrice(Ticker $ticker, BuyOrder $order): bool
    {
        if ($order->getPositionSide() === Side::Sell) {
            return $ticker->indexPrice < $order->getPrice();
        }

        if ($order->getPositionSide() === Side::Buy) {
            return $ticker->indexPrice > $order->getPrice();
        }

        throw new \LogicException(\sprintf('Unexpected positionSide "%s"', $order->getPositionSide()->value));
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

                $stopData = $this->createStop($position, $ticker, $buyOrder);

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
    private function createStop(Position $position, Ticker $ticker, BuyOrder $buyOrder): array
    {
        $oppositePriceDelta = $buyOrder->getVolume() >= 0.005
            ? self::REGULAR_ORDER_STOP_DISTANCE
            : self::ADDITION_ORDER_STOP_DISTANCE;

//        $currentPositionIsHedgePosition = $this->getOppositePosition($position)->size > $position->size;
        $isHedge = $this->getOppositePosition($position) !== null;

        $basePrice = $buyOrder->getOriginalPrice() ?? $buyOrder->getPrice();

        $triggerPrice = $isHedge
            ? ($position->side === Side::Sell ? \ceil($position->entryPrice) + 10.5 : \ceil($position->entryPrice) - 10.5) // @todo придумать логику
            : ($position->side === Side::Sell ? $basePrice + $oppositePriceDelta : $basePrice - $oppositePriceDelta);

        $stopId = $this->stopService->create(
            $ticker,
            $position->side,
            $triggerPrice,
            $buyOrder->getVolume(),
            self::STOP_ORDER_TRIGGER_DELTA,
            ['onlyAfterExchangeOrderExecuted' => $buyOrder->getExchangeOrderId()],
        );

        return ['id' => $stopId, 'triggerPrice' => $triggerPrice];
    }

    private function getPositionData(Symbol $symbol, Side $side): PositionData
    {
        if (
            !($positionData = $this->positionsData[$symbol->value . $side->value] ?? null)
            || $positionData->needUpdate($this->clock->now())
        ) {
            $position = $this->positionService->getOpenedPositionInfo($symbol, $side);
            $this->info(
                \sprintf(
                    'UPD %s | %.3f btc (%.2f usdt) | entry: $%.2f | liq: $%.2f',
                    $position->getCaption(),
                    $position->size,
                    $position->positionValue,
                    $position->entryPrice,
                    $position->liquidationPrice,
                ));

            $this->positionsData[$symbol->value . $side->value] = new PositionData($position, $this->clock->now());
        }

        return $this->positionsData[$symbol->value . $side->value];
    }

    private function getOppositePosition(Position $position): ?Position
    {
        return $this->getPositionData($position->symbol, $position->side === Side::Buy ? Side::Sell : Side::Buy)->position;
    }
}
