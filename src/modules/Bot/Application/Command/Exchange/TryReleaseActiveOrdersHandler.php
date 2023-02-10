<?php

declare(strict_types=1);

namespace App\Bot\Application\Command\Exchange;

use App\Bot\Application\Command\CreateBuyOrder;
use App\Bot\Application\Service\Exchange\ExchangeOrdersServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\BuyOrderRepository;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Infrastructure\ByBit\PositionService;
use App\Bot\Service\Stop\StopService;
use App\Trait\DispatchCommandTrait;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class TryReleaseActiveOrdersHandler
{
    private const RELEASE_OVER_DISTANCE = 35;
    private const DEFAULT_TRIGGER_DELTA = 10;

    public function __construct(
        private readonly ExchangeOrdersServiceInterface $exchangeOrdersService,
        private readonly PositionServiceInterface $positionService,
        private readonly StopService $stopService,
    ) {

    }

    public function __invoke(TryReleaseActiveOrders $command): void
    {
        if (\count($activeOrders = $this->exchangeOrdersService->getActiveConditionalOrders($command->symbol)) < 7) {
            return;
        }

        $ticker = $this->positionService->getTickerInfo($command->symbol);

        foreach ($activeOrders as $order) {
            if (
                abs($order->triggerPrice - $ticker->indexPrice) > self::RELEASE_OVER_DISTANCE
                || $order->positionSide === Side::Sell && $ticker->indexPrice < $order->triggerPrice
                || $order->positionSide === Side::Buy && $ticker->indexPrice > $order->triggerPrice
            ) {
                $this->exchangeOrdersService->closeActiveConditionalOrder($order);

                $this->stopService->create($ticker, $order->positionSide, $order->triggerPrice, $order->volume, self::DEFAULT_TRIGGER_DELTA);
            }
        }
    }
}
