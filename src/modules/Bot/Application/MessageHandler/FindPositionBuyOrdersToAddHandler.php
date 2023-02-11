<?php

declare(strict_types=1);

namespace App\Bot\Application\MessageHandler;

use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Message\FindPositionBuyOrdersToAdd;
use App\Bot\Domain\BuyOrderRepository;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Bot\Application\Exception\MaxActiveCondOrdersCountReached;
use App\Bot\Infrastructure\ByBit\PositionService;
use App\Bot\Service\Stop\StopService;
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

    private ?Ticker $lastTicker = null;

    public function __construct(
        private readonly PositionService $positionService,
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly StopService $stopService,
        private readonly MessageBusInterface $messageBus,
        LoggerInterface $logger,
    ) {
        $this->logger = $logger;
    }

    public function __invoke(FindPositionBuyOrdersToAdd $message): void
    {
        // Fake
        $position = new Position($message->side, Symbol::BTCUSDT, 23100, 0.3, 23300);

        $orders = $this->buyOrderRepository->findActiveByPositionNearTicker($position, $this->lastTicker);
        $ticker = $this->positionService->getTickerInfo($message->symbol);

        foreach ($orders as $order) {
            $delta = $order->getTriggerDelta() ?: self::DEFAULT_TRIGGER_DELTA;
            if (abs($order->getPrice() - $ticker->indexPrice) <= $delta) {
                $this->addBuyOrder($position, $ticker, $order);
            } elseif ($this->isCurrentIndexPriceAlreadyOverOrderPrice($ticker, $order)) {
                $price = $order->getPositionSide() === Side::Sell ? $ticker->indexPrice - 10 : $ticker->indexPrice + 10;
                $order->setPrice($price);

                $this->addBuyOrder($position, $ticker, $order);
            }
        }

        $this->lastTicker = $ticker;

        $this->info(\sprintf('%s: %.2f', $message->symbol->value, $ticker->indexPrice));
    }

    /**
     * @throws \LogicException
     */
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

    private function addBuyOrder(Position $position, Ticker $ticker, BuyOrder $order): void
    {
        $price = $order->getPrice();

        try {
            $orderId = $this->positionService->addBuyOrder($position, $ticker, $price, $order->getVolume());

            if ($orderId) {
                if ($order->getVolume() <= 0.005) {
                    $this->buyOrderRepository->remove($order);
                } else {
                    $order->addToContext('buyOrderId', $orderId);
                    $this->buyOrderRepository->save($order);
                }

//                $stopData = $position->side !== Side::Buy ? $this->createStop($ticker, $order) : [];
                $stopData = $this->createStop($ticker, $order);

                $this->info(
                    \sprintf('Buy order on %s successfully pushed to exchange', $position->getCaption()),
                    ['orderId' => $orderId, 'softStop' => $stopData],
                );
            }
        } catch (MaxActiveCondOrdersCountReached $e) {
            $this->warning($e->getMessage() . PHP_EOL, ['price' => $price]);
            $this->messageBus->dispatch(new TryReleaseActiveOrders($ticker->symbol));
        }
    }

    /**
     * @param Ticker $ticker
     * @param BuyOrder $buyOrder
     *
     * @return array{}
     */
    private function createStop(Ticker $ticker, BuyOrder $buyOrder): array
    {
        $oppositePriceDelta = $buyOrder->getVolume() >= 0.005
            ? self::REGULAR_ORDER_STOP_DISTANCE
            : self::ADDITION_ORDER_STOP_DISTANCE;

//        $price = $buyOrder->getPrice();
        $price = $buyOrder->originalPrice ?: $buyOrder->getPrice();
        $triggerPrice = $buyOrder->getPositionSide() === Side::Sell
            ? $price + $oppositePriceDelta
            : $price - $oppositePriceDelta;

        $stopId = $this->stopService->create(
            $ticker,
            $buyOrder->getPositionSide(),
            $triggerPrice,
            $buyOrder->getVolume(),
            self::STOP_ORDER_TRIGGER_DELTA,
        );

        return [$stopId, $triggerPrice];
    }
}
