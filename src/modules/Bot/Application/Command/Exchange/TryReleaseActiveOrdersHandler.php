<?php

declare(strict_types=1);

namespace App\Bot\Application\Command\Exchange;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Service\Stop\StopService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TryReleaseActiveOrdersHandler
{
    private const MAX_ORDER_MUST_LEFT = 3;
    private const RELEASE_OVER_DISTANCE = 30;

    // @todo Всё это лучше вынести в настройки
    // С человекопонятными названиями
    private const DEFAULT_TRIGGER_DELTA = 3;

    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly StopService $stopService,
    ) {
    }

    public function __invoke(TryReleaseActiveOrders $command): void
    {
        $activeOrders = $this->exchangeService->getActiveConditionalOrders($command->symbol);

        $ticker = $this->exchangeService->getTicker($command->symbol);

        $claimedOrderVolume = $command->forVolume;

        foreach ($activeOrders as $key => $order) {
            if (!$command->force && \count($activeOrders) < self::MAX_ORDER_MUST_LEFT) {
                return;
            }

            if (
                abs($order->triggerPrice - $ticker->indexPrice) > self::RELEASE_OVER_DISTANCE
//                || ($order->positionSide === Side::Sell && $ticker->indexPrice < $order->triggerPrice)
//                || ($order->positionSide === Side::Buy && $ticker->indexPrice > $order->triggerPrice)
            ) {
                $this->release($order);
            } elseif ($claimedOrderVolume !== null && $order->volume < $claimedOrderVolume) {
                // Force in case of volume of active order less than claimed volume of new order
                $claimedOrderVolume = null; // Only once
                $this->release($order);
            }

            unset($activeOrders[$key]);
        }
    }

    private function release(ActiveStopOrder $order): void
    {
        $this->exchangeService->closeActiveConditionalOrder($order);

        $this->stopService->create($order->positionSide, $order->triggerPrice, $order->volume, self::DEFAULT_TRIGGER_DELTA);
    }
}
