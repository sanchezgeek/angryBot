<?php

declare(strict_types=1);

namespace App\Bot\Application\MessageHandler;

use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Message\CheckPositionJob;
use App\Bot\Application\Message\FindPositionStopsToAdd;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Bot\Service\Buy\BuyOrderService;
use App\Bot\Application\Exception\MaxActiveCondOrdersCountReached;
use App\Bot\Infrastructure\ByBit\PositionService;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class FindPositionStopsToAddHandler
{
    use LoggerTrait;

    private const DEFAULT_TRIGGER_DELTA = 25;
    public function __construct(
        private readonly PositionService $positionService,
        private readonly StopRepository $stopRepository,
        private readonly BuyOrderService $buyOrderService,
        private readonly MessageBusInterface $messageBus,
        LoggerInterface $logger,
    ) {
        $this->logger = $logger;
    }

    public function __invoke(FindPositionStopsToAdd $message): void
    {
//        $position = $this->positionService->getPosition($message->symbol, $message->side);
//        if (!$position) {
//            return;
//        }

        // Fake
        $position = new Position($message->side, Symbol::BTCUSDT, 23100, 0.3, 23300);

        $stops = $this->stopRepository->findActiveByPosition($position);
        $ticker = $this->positionService->getTickerInfo($message->symbol);

        foreach ($stops as $stop) {
            $delta = $stop->getTriggerDelta() ?: self::DEFAULT_TRIGGER_DELTA;
            if ($this->isCurrentIndexPriceOverStop($ticker, $stop)) {
                $price = $stop->getPositionSide() === Side::Sell ? $ticker->indexPrice + 10 : $ticker->indexPrice - 10;
                $stop->setPrice($price);

                $this->addStop($position, $ticker, $stop);
            } elseif (abs($stop->getPrice() - $ticker->indexPrice) <= $delta) {
                $this->addStop($position, $ticker, $stop);

                $price = $stop->getPositionSide() === Side::Sell ? $ticker->indexPrice + 10 : $ticker->indexPrice - 10;
                $stop->setPrice($price);

                $this->addStop($position, $ticker, $stop);
            }
        }

        $this->info(\sprintf('%s: %.2f', $message->symbol->value, $ticker->indexPrice));
    }

    /**
     * @throws \LogicException
     */
    private function isCurrentIndexPriceOverStop(Ticker $ticker, Stop $stop): bool
    {
        if ($stop->getPositionSide() === Side::Sell) {
            return $ticker->indexPrice > $stop->getPrice();
        }

        if ($stop->getPositionSide() === Side::Buy) {
            return $ticker->indexPrice < $stop->getPrice();
        }

        throw new \LogicException(\sprintf('Unexpected positionSide "%s"', $stop->getPositionSide()->value));
    }

    private function addStop(Position $position, Ticker $ticker, Stop $stop): void
    {
        $price = $stop->getPrice();

        try {
            $stopOrderId = $this->positionService->addStop($position, $ticker, $price, $stop->getVolume());

            if ($stopOrderId) {
                if ($stop->getVolume() <= 0.005) {
                    $this->stopRepository->remove($stop);
                } else {
                    $stop->addToContext('stopOrderId', $stopOrderId);
                    $this->stopRepository->save($stop);
                }
                $oppositeBuyOrderData = $this->createOppositeBuyOrder($ticker, $stop);

                $this->info(
                    \sprintf('SL on %s successfully pushed to exchange', $position->getCaption()),
                    ['orderId' => $stopOrderId, 'oppositeBuyOrder' => $oppositeBuyOrderData],
                );
            }
        } catch (MaxActiveCondOrdersCountReached $e) {
            $this->warning($e->getMessage() . PHP_EOL, ['price' => $price]);
            $this->messageBus->dispatch(new TryReleaseActiveOrders($ticker->symbol));
        }
    }

    private function createOppositeBuyOrder(Ticker $ticker, Stop $stop): array
    {
        $price = $stop->originalPrice ?: $stop->getPrice();
        $triggerPrice = $stop->getPositionSide() === Side::Sell ? $price - self::BUY_ORDER_OPPOSITE_PRICE_DELTA : $price + self::BUY_ORDER_OPPOSITE_PRICE_DELTA;

        $orderId = $this->buyOrderService->create(
            $ticker,
            $stop->getPositionSide(),
            $triggerPrice,
            $stop->getVolume() >= 0.006 ? $stop->getVolume() / 2 : $stop->getVolume(),
            self::BUY_ORDER_TRIGGER_DELTA,
        );

        return [$orderId, $triggerPrice];
    }

    private const BUY_ORDER_TRIGGER_DELTA = 1;
    private const BUY_ORDER_OPPOSITE_PRICE_DELTA = 30;
}
