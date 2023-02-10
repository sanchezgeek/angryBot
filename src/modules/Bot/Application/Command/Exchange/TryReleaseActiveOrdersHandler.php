<?php

declare(strict_types=1);

namespace App\Bot\Application\Command\Exchange;

use App\Bot\Application\Command\CreateBuyOrder;
use App\Bot\Application\Service\Exchange\ExchangeOrdersServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\BuyOrderRepository;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Infrastructure\ByBit\PositionService;
use App\Bot\Service\Stop\StopService;
use App\Trait\DispatchCommandTrait;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class TryReleaseActiveOrdersHandler
{
    private const RELESE_OVER_DISTANCE = 60;
    private const DEFAULT_TRIGGER_DELTA = 10;

    public function __construct(
        private readonly ExchangeOrdersServiceInterface $exchangeOrdersService,
        private readonly PositionServiceInterface $positionService,
        private readonly StopService $stopService,
    ) {

    }

    public function __invoke(TryReleaseActiveOrders $command): void
    {
        $activeOrders = $this->exchangeOrdersService->getActiveConditionalOrders($command->symbol);

        $ticker = $this->positionService->getTickerInfo($command->symbol);

        if (\count($activeOrders) > 7) {
            foreach ($activeOrders as $order) {
                if (abs($order->triggerPrice - $ticker->indexPrice) > self::RELESE_OVER_DISTANCE) {
                    $this->exchangeOrdersService->closeActiveConditionalOrder($order);

                    $this->stopService->create($ticker, $order->positionSide, $order->triggerPrice, $order->volume, self::DEFAULT_TRIGGER_DELTA);
                }
            }
        }
    }
}
