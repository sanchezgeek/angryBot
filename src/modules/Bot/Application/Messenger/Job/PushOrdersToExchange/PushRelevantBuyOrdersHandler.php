<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Application\Command\Exchange\IncreaseHedgeSupportPositionByGetProfitFromMain;
use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Exception\ApiRateLimitReached;
use App\Bot\Application\Exception\CannotAffordOrderCost;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Hedge\Hedge;
use App\Bot\Application\Service\Strategy\Hedge\HedgeOppositeStopCreate;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Application\Exception\MaxActiveCondOrdersQntReached;
use App\Bot\Service\Stop\StopService;
use App\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class PushRelevantBuyOrdersHandler extends AbstractOrdersPusher
{
    private const DEFAULT_TRIGGER_DELTA = 1;
    private const STOP_ORDER_TRIGGER_DELTA = 3;
    private const REGULAR_ORDER_STOP_DISTANCE = 45;
    private const ADDITION_ORDER_STOP_DISTANCE = 70;

//    private const HEDGE_POSITION_REGULAR__ORDER_STOP_DISTANCE = 45;
//    private const HEDGE_POSITION_ADDITION_ORDER_STOP_DISTANCE = 70;

    private ?Ticker $lastTicker = null;
    private ?float $cannotAffordAtPrice = null;

    public function __construct(
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly StopRepository $stopRepository,
        private readonly StopService $stopService,
        private readonly MessageBusInterface $messageBus,

        PositionServiceInterface $positionService,
        LoggerInterface $logger,
        ClockInterface $clock,
    ) {
        parent::__construct($positionService, $clock, $logger);
    }

    private function cannotRunDueToCannotAffordBuy(Ticker $ticker): bool
    {
        if ($this->cannotAffordAtPrice !== null) {
            $range = [$ticker->indexPrice - 10, $ticker->indexPrice + 10];

            return $this->cannotAffordAtPrice > $range[0] && $this->cannotAffordAtPrice < $range[1];
        }

        return false;
    }

    public function __invoke(PushRelevantBuyOrders $message): void
    {
        $positionData = $this->getPositionData($message->symbol, $message->side);
//        if (!$positionData->isPositionOpened()) {
//            return;
//        }

        $orders = $this->buyOrderRepository->findActive($positionData->position->side, $this->lastTicker);
        $ticker = $this->positionService->getTicker($message->symbol);

        // To not make extra queries to Exchange (what can lead to a ban due to ApiRateLimitReached)
        if ($this->cannotRunDueToCannotAffordBuy($ticker)) {
            $this->info(
                \sprintf('Skipp relevant buy orders check at $%.2f price (can not afford)', $ticker->indexPrice),
            );
            return;
        }

        $this->cannotAffordAtPrice = null;

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

            // @todo Есть косяк: выше проставляется новый price в расчёте на то, что тут будет ордер на бирже. А его нет. Денег не хватило. Но ниже делается persist. originalPrice попадает в базу. А на самом деле ордер не был отправлен.
            // Нужно новую цену не сразу фигачить в поле, а помещать в контекст и тут уже применять. Если вернулся $exchangeOrderId

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
                        '%sBuy%s %.3f | $%.2f (stop: $%.2f with %s strategy)',
                        $sign = ($position->side === Side::Sell ? '---' : '+++'), $sign,
                        $buyOrder->getVolume(),
                        $buyOrder->getPrice(),
                        $stopData['triggerPrice'],
                        $stopData['strategy'],
                    ),
                    ['exchange.orderId' => $exchangeOrderId, '`stop`' => $stopData],
                );
            }
        } catch (ApiRateLimitReached $e) {
            $this->logExchangeClientException($e);

            $this->sleep($e->getMessage());
        } catch (MaxActiveCondOrdersQntReached $e) {
            $this->logExchangeClientException($e);

            $this->messageBus->dispatch(
                TryReleaseActiveOrders::forBuyOrder($ticker->symbol, $buyOrder)
            );
        } catch (CannotAffordOrderCost $e) {
            $this->cannotAffordAtPrice = $ticker->indexPrice;

            $this->logExchangeClientException($e);

            $isHedge = ($oppositePosition = $this->getOppositePosition($position)) !== null;
            if ($isHedge) {
                $hedge = Hedge::create($position, $oppositePosition);

                if ($hedge->isSupportPosition($position) && $hedge->needIncreaseSupport()) {
                    $this->messageBus->dispatch(
                        new IncreaseHedgeSupportPositionByGetProfitFromMain($e->symbol, $e->side, $e->qty)
                    );
                }
                // elseif ($hedge->isMainPosition($position)) @todo придумать логику по восстановлению убытков главной позиции
                // если $this->hedgeService->createStopIncrementalGridBySupport($hedge, $stop) (@see PushRelevantStopsHandler) окажется неработоспособной
                // например, если на момент проверки ещё нужно было держать объём саппорта и сервис не был вызван
            }
        }
    }

    /**
     * @return array{id: int, triggerPrice: float, strategy: string}
     */
    private function createOpposite(Position $position, Ticker $ticker, BuyOrder $buyOrder): array
    {
        $triggerPrice = null;
        $selectedStrategy = 'default';
        $positionSide = $position->side;

        $volume = $buyOrder->getVolume();

        $oppositePriceDelta = $volume >= 0.005
            ? self::REGULAR_ORDER_STOP_DISTANCE
            : self::ADDITION_ORDER_STOP_DISTANCE;

        $isHedge = ($oppositePosition = $this->getOppositePosition($position)) !== null;
        if ($isHedge) {
            $basePrice = null;

            $hedge = Hedge::create($position, $oppositePosition);

            $hedgeStrategy = $hedge->getHedgeStrategy();

            $stopStrategy = $hedge->isSupportPosition($position) ? $hedgeStrategy->supportPositionOppositeStopCreation : $hedgeStrategy->mainPositionOppositeStopCreation;

            if (
                (
                    $stopStrategy === HedgeOppositeStopCreate::AFTER_FIRST_POSITION_STOP
                ) || (
                    $stopStrategy === HedgeOppositeStopCreate::ONLY_BIG_SL_AFTER_FIRST_POSITION_STOP
                    && $volume >= HedgeOppositeStopCreate::BIG_SL_VOLUME_STARTS_FROM
                )
            ) {
                try {
                    if ($firstPositionStop = $this->stopRepository->findFirstStopUnderPosition($position)) {
                        $basePrice = $firstPositionStop->getPrice();
                    }
                } catch (\Throwable $e) {
                    $this->logger->critical('Cannot find first stop. See logs');
                }
            } elseif (
                (
                    $stopStrategy === HedgeOppositeStopCreate::UNDER_POSITION
                ) || (
                    $stopStrategy === HedgeOppositeStopCreate::ONLY_BIG_SL_UNDER_POSITION
                    && $volume >= HedgeOppositeStopCreate::BIG_SL_VOLUME_STARTS_FROM
                )
            ) {
                $positionPrice = \ceil($position->entryPrice);
                $basePrice = $ticker->isIndexPriceAlreadyOverStopPrice($positionSide, $positionPrice) ? $ticker->indexPrice : $positionPrice; // tmp

                $basePrice = $positionSide === Side::Buy ? $basePrice - 25 : $basePrice + 25;
            }

            if ($basePrice) {
                if (!$ticker->isIndexPriceAlreadyOverStopPrice($positionSide, $basePrice)) {
                    $selectedStrategy = $stopStrategy->value . ($hedgeStrategy->description ? ('::' . $hedgeStrategy->description) : '');
                    $triggerPrice = $positionSide === Side::Sell ? $basePrice + 1 : $basePrice - 1;
                } else {
                    $selectedStrategy = 'default (\'cause index price over stop)';
                }
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
            $volume,
            self::STOP_ORDER_TRIGGER_DELTA,
//            ['onlyAfterExchangeOrderExecuted' => $buyOrder->getExchangeOrderId()], On ByBit Buy happens immediately
        );

        return ['id' => $stopId, 'triggerPrice' => $triggerPrice, 'strategy' => $selectedStrategy];
    }
}
