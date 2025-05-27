<?php

declare(strict_types=1);

namespace App\Bot\Application\Command\Exchange;

use App\Bot\Application\Events\Stop\ActiveCondStopMovedBack;
use App\Bot\Application\Helper\StopHelper;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\SymbolPrice;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

use function count;

/** @see TryReleaseActiveOrdersHandlerTest */
#[AsMessageHandler]
final class TryReleaseActiveOrdersHandler
{
    private const MIN_LEFT_ORDERS_QNT_PER_SYMBOL = 3;

    /** @var array<ActiveStopOrder[]> */
    private array $activeConditionalStopOrders = [];

    /**
     * @param ByBitLinearExchangeService $exchangeService
     */
    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionServiceInterface $positionService,
        private readonly StopService $stopService,
        private readonly StopRepository $stopRepository,
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly EventDispatcherInterface $events,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(TryReleaseActiveOrders $message): void
    {
        $messages = [];
        if ($message->isMessageForAllSymbols()) {
            foreach ($this->positionService->getOpenedPositionsSymbols() as $symbol) {
                $messages[] = $message->cloneForSymbol($symbol);
            }
            $activeConditionalStopOrders = $this->exchangeService->activeConditionalOrders();
        } else {
            $messages[] = $message;
            $activeConditionalStopOrders = $this->exchangeService->activeConditionalOrders($message->symbol);
        }

        foreach ($messages as $msg) {
            $symbol = $msg->symbol;

            $this->activeConditionalStopOrders[$symbol->value] = array_filter(
                $activeConditionalStopOrders,
                static fn(ActiveStopOrder $activeStopOrder) => $activeStopOrder->symbol === $symbol
            );

            $this->handleMessage($msg);
        }
    }

    public function handleMessage(TryReleaseActiveOrders $command): void
    {
        $symbol = $command->symbol;
        $claimedOrderVolume = $command->forVolume;

        $activeOrders = $this->activeConditionalStopOrders[$symbol->value];
        if (!count($activeOrders)) {
            return;
        }

        $ticker = $this->exchangeService->ticker($symbol);
        $defaultReleaseOverDistance = StopHelper::defaultReleaseStopsDistance($ticker->indexPrice);

        /** @var SymbolPrice[] $compareWithPrices */
        $compareWithPrices = [];
        $compareWithPrices[Side::Sell->value] = PriceHelper::max($ticker->indexPrice, $ticker->markPrice);
        $compareWithPrices[Side::Buy->value] = PriceHelper::min($ticker->indexPrice, $ticker->markPrice);

        foreach ($activeOrders as $key => $order) {
            if (!$command->force && \count($activeOrders) < self::MIN_LEFT_ORDERS_QNT_PER_SYMBOL) {
                return;
            }

            // @todo | m.b. `...ByIds` ?
            $existedStop = $this->stopRepository->findByExchangeOrderId($order->positionSide, $order->orderId);
            $distance = $existedStop ? $existedStop->getTriggerDelta() + StopHelper::additionalTriggerDeltaIfCurrentPriceOverStop($symbol) : $defaultReleaseOverDistance;

            if ($distance < $defaultReleaseOverDistance) {
                $distance = $defaultReleaseOverDistance;
            }

            if (
                $compareWithPrices[$order->positionSide->value]->deltaWith($order->triggerPrice) > $distance
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

        try {
            $this->entityManager->beginTransaction();
            $this->doRelease($exchangeStop, $existedStop);
            $this->entityManager->commit();
        } catch (Throwable $e) {
            $this->entityManager->rollback();
            $position = $this->positionService->getPosition($exchangeStop->symbol, $exchangeStop->positionSide);
            $newExchangeOrderId = $this->positionService->addConditionalStop($position, $exchangeStop->triggerPrice, $exchangeStop->volume, TriggerBy::from($exchangeStop->triggerBy));

            if ($existedStop) {
                $existedStop->setExchangeOrderId($newExchangeOrderId);
                $this->stopRepository->save($existedStop);
            }

            # DRY
            $oppositeOrders = $this->buyOrderRepository->findOppositeToStopByExchangeOrderId($exchangeStop->positionSide, $exchangeStop->orderId);
            foreach ($oppositeOrders as $oppositeOrder) {
                $oppositeOrder->setOnlyAfterExchangeOrderExecutedContext($newExchangeOrderId);
                $this->buyOrderRepository->save($oppositeOrder);
            }

            throw $e;
        }

        if ($existedStop) {
            $this->events->dispatch(new ActiveCondStopMovedBack($existedStop));
        }
    }

    private function doRelease(ActiveStopOrder $exchangeStop, ?Stop $existedStop): void
    {
        $side = $exchangeStop->positionSide;
        $symbol = $exchangeStop->symbol;


        if ($existedStop) {
            $existedStop->clearExchangeOrderId();

            $addTriggerDelta = StopHelper::additionalTriggerDeltaIfCurrentPriceOverStop($symbol);
            $existedStop->increaseTriggerDelta($addTriggerDelta); // Increase triggerDelta little bit

            $this->stopRepository->save($existedStop);
            // @todo | stop | maybe ->setPrice(context.originalPrice) if now ticker.indexPrice above originalPrice?
        } else {
            $this->stopService->create($symbol, $side, $exchangeStop->triggerPrice, $exchangeStop->volume, $exchangeStop->symbol->stopDefaultTriggerDelta(), [
                'fromExchangeWithoutExistedStop' => true
            ]);
        }

        $oppositeOrders = $this->buyOrderRepository->findOppositeToStopByExchangeOrderId($side, $exchangeStop->orderId);
        foreach ($oppositeOrders as $oppositeOrder) {
            $this->buyOrderRepository->remove($oppositeOrder);
        }
    }
}
