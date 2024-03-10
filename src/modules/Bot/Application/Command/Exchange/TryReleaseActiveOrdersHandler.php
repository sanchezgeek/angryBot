<?php

declare(strict_types=1);

namespace App\Bot\Application\Command\Exchange;

use App\Bot\Application\Events\Stop\ActiveCondStopMovedBack;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function count;

/** @see TryReleaseActiveOrdersHandlerTest */
#[AsMessageHandler]
final class TryReleaseActiveOrdersHandler
{
    private const MIN_LEFT_ORDERS_QNT = 3;
    private const DEFAULT_TRIGGER_DELTA = 20;
    private const DEFAULT_RELEASE_OVER_DISTANCE = 90;

    /**
     * @param ByBitLinearExchangeService $exchangeService
     */
    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly StopService $stopService,
        private readonly StopRepository $stopRepository,
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * @throws UnknownByBitApiErrorException
     * @throws ApiRateLimitReached
     */
    public function __invoke(TryReleaseActiveOrders $command): void
    {
        $claimedOrderVolume = $command->forVolume;

        $activeOrders = $this->exchangeService->activeConditionalOrders($command->symbol);
        if (!count($activeOrders)) {
            return;
        }

        $ticker = $this->exchangeService->ticker($command->symbol);

        foreach ($activeOrders as $key => $order) {
            if (!$command->force && \count($activeOrders) < self::MIN_LEFT_ORDERS_QNT) {
                return;
            }

            $existedStop = $this->stopRepository->findByExchangeOrderId($order->positionSide, $order->orderId);
            $distance = $existedStop ? $existedStop->getTriggerDelta() + 10 : self::DEFAULT_RELEASE_OVER_DISTANCE;

            if ($distance < self::DEFAULT_RELEASE_OVER_DISTANCE) {
                $distance = self::DEFAULT_RELEASE_OVER_DISTANCE;
            }

            if (
                $ticker->indexPrice->deltaWith($order->triggerPrice) > $distance
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

    private function release(ActiveStopOrder $exchangeStop, ?Stop $existedStop): void
    {
        $this->exchangeService->closeActiveConditionalOrder($exchangeStop);
        $side = $exchangeStop->positionSide;

        if ($existedStop) {
            $existedStop->clearExchangeOrderId();
            $existedStop->setTriggerDelta($existedStop->getTriggerDelta() + 3); // Increase triggerDelta little bit
            $this->stopRepository->save($existedStop);

            // @todo | stop | maybe ->setPrice(context.originalPrice) if now ticker.indexPrice above originalPrice?

            $this->events->dispatch(new ActiveCondStopMovedBack($existedStop));
        } else {
            $this->stopService->create($side, $exchangeStop->triggerPrice, $exchangeStop->volume, self::DEFAULT_TRIGGER_DELTA);
        }

        $oppositeOrders = $this->buyOrderRepository->findOppositeToStopByExchangeOrderId($side, $exchangeStop->orderId);
        foreach ($oppositeOrders as $oppositeOrder) {
            $this->buyOrderRepository->remove($oppositeOrder);
        }
    }
}
