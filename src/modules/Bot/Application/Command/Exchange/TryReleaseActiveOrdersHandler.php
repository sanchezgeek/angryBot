<?php

declare(strict_types=1);

namespace App\Bot\Application\Command\Exchange;

use App\Bot\Application\Events\Stop\ActiveCondStopMovedBack;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Repository\StopRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TryReleaseActiveOrdersHandler
{
    private const MAX_ORDER_MUST_LEFT = 3;
    private const DEFAULT_RELEASE_OVER_DISTANCE = 220;

    // @todo Всё это лучше вынести в настройки
    // С человекопонятными названиями
    private const DEFAULT_TRIGGER_DELTA = 9;

    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly StopService $stopService,
        private readonly StopRepository $stopRepository,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(TryReleaseActiveOrders $command): void
    {
        // @todo также надо удалять oppositeBuyOrders. Иначе будет нежданчик

        $activeOrders = $this->exchangeService->activeConditionalOrders($command->symbol);

        $ticker = $this->exchangeService->ticker($command->symbol);

        $claimedOrderVolume = $command->forVolume;

        foreach ($activeOrders as $key => $order) {
            if (!$command->force && \count($activeOrders) < self::MAX_ORDER_MUST_LEFT) {
                return;
            }

            $existedStop = $this->stopRepository->findByExchangeOrderId($order->positionSide, $order->orderId);
            $distance = $existedStop ? $existedStop->getTriggerDelta() + 10 : self::DEFAULT_RELEASE_OVER_DISTANCE;

            if ($distance < self::DEFAULT_RELEASE_OVER_DISTANCE) {
                $distance = self::DEFAULT_RELEASE_OVER_DISTANCE;
            }

            if (
                abs($order->triggerPrice - $ticker->indexPrice) > $distance
//                || ($order->positionSide === Side::Sell && $ticker->indexPrice < $order->triggerPrice)
//                || ($order->positionSide === Side::Buy && $ticker->indexPrice > $order->triggerPrice)
            ) {
                $this->release($order, $existedStop);
            } elseif ($claimedOrderVolume !== null && $order->volume < $claimedOrderVolume) {
                // Force in case of volume of active order less than claimed volume of new order
                $claimedOrderVolume = null; // Only once
                $this->release($order, $existedStop);
            }

            unset($activeOrders[$key]);
        }
    }

    private function release(ActiveStopOrder $order, ?Stop $stop): void
    {
        $this->exchangeService->closeActiveConditionalOrder($order);

        if ($stop) {
            $stop->clearExchangeOrderId();
            $stop->setTriggerDelta($stop->getTriggerDelta() + 3); // Increase triggerDelta little bit

            $this->stopRepository->save($stop);

            $this->events->dispatch(new ActiveCondStopMovedBack($stop));
        } else {
            $this->stopService->create($order->positionSide, $order->triggerPrice, $order->volume, self::DEFAULT_TRIGGER_DELTA);
        }
    }
}
