<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Application\Command\Exchange\IncreaseHedgeSupportPositionByGetProfitFromMain;
use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Exception\ApiRateLimitReached;
use App\Bot\Application\Exception\CannotAffordOrderCost;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
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
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class PushRelevantBuyOrdersHandler extends AbstractOrdersPusher
{
    private const DEFAULT_TRIGGER_DELTA = 1;
    private const STOP_ORDER_TRIGGER_DELTA = 5;
    private const REGULAR_ORDER_STOP_DISTANCE = 45;
    private const ADDITION_ORDER_STOP_DISTANCE = 57;

//    private const HEDGE_POSITION_REGULAR__ORDER_STOP_DISTANCE = 45;
//    private const HEDGE_POSITION_ADDITION_ORDER_STOP_DISTANCE = 70;

    private ?\DateTimeImmutable $cannotAffordAt = null;
    private ?float $cannotAffordAtPrice = null;

    public function __construct(
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly StopRepository $stopRepository,
        private readonly StopService $stopService,
        private readonly MessageBusInterface $messageBus,

        ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        LoggerInterface $logger,
        ClockInterface $clock,
    ) {
        parent::__construct($exchangeService, $positionService, $clock, $logger);
    }

    public function __invoke(PushRelevantBuyOrders $message): void
    {
        $positionData = $this->getPositionData($message->symbol, $message->side, true);
        if (!$positionData->isPositionOpened()) {
            return;
        }

        $side = $positionData->position->side;
        $ticker = $this->exchangeService->getTicker($message->symbol);

        if (!$this->canAffordBuy($ticker)) {
            $this->info(\sprintf('Skip relevant buy orders check at $%.2f price (can not afford)', $ticker->indexPrice));
            return;
        }
        $this->cannotAffordAtPrice = null;
        $this->cannotAffordAt = null;

        $orders = $this->buyOrderRepository->findActiveInRange(
            side: $side,
            from: ($side === Side::Sell ? $ticker->indexPrice - 10  : $ticker->indexPrice + 10),
            to: ($side === Side::Sell ? $ticker->indexPrice + 15  : $ticker->indexPrice - 15),
            // To get the cheapest orders (to ignore sleep by CannotAffordOrderCost in case of can afford buy less qty)
            qbModifier: static fn (QueryBuilder $qb) => $qb->addOrderBy($qb->getRootAliases()[0] . '.volume', 'asc')->addOrderBy($qb->getRootAliases()[0] . '.price', $side === Side::Sell ? 'asc' : 'desc')
        );

        try {
            foreach ($orders as $order) {
                if ($order->mustBeExecuted($ticker)) {
                    $this->buy($positionData->position, $ticker, $order);
                }
            }
        } catch (CannotAffordOrderCost $e) {
            $position = $positionData->position;
            $this->cannotAffordAtPrice = $ticker->indexPrice;
            $this->cannotAffordAt = $this->clock->now();

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
     * @throws CannotAffordOrderCost
     */
    private function buy(Position $position, Ticker $ticker, BuyOrder $order): void
    {
        try {
            $exchangeOrderId = $this->positionService->addBuyOrder($position, $ticker, $order->getPrice(), $order->getVolume());

            if ($exchangeOrderId) {
                $order->setExchangeOrderId($exchangeOrderId);

                if ($order->getVolume() <= 0.005) {
                    $this->buyOrderRepository->remove($order);
                } else {
                    $this->buyOrderRepository->save($order);
                }

                $stopData = $this->createStop($position, $ticker, $order);

                $this->info(
                    \sprintf(
                        '%sBuy%s %.3f | $%.2f (stop: $%.2f with %s strategy)',
                        $sign = ($position->side === Side::Sell ? '---' : '+++'), $sign,
                        $order->getVolume(),
                        $order->getPrice(),
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
                TryReleaseActiveOrders::forBuyOrder($ticker->symbol, $order)
            );
        }
    }

    /**
     * @return array{id: int, triggerPrice: float, strategy: string}
     */
    private function createStop(Position $position, Ticker $ticker, BuyOrder $buyOrder): array
    {
        $triggerPrice = null;
        $selectedStrategy = 'default';
        $side = $position->side;
        $volume = $buyOrder->getVolume();

        $isHedge = ($oppositePosition = $this->getOppositePosition($position)) !== null;
        if ($isHedge) {
            $hedge = Hedge::create($position, $oppositePosition);
            $hedgeStrategy = $hedge->getHedgeStrategy();
            $stopStrategy = $hedge->isSupportPosition($position) ? $hedgeStrategy->supportPositionOppositeStopCreation : $hedgeStrategy->mainPositionOppositeStopCreation;

            $basePrice = null;
            if (
                (
                    $stopStrategy === HedgeOppositeStopCreate::AFTER_FIRST_POSITION_STOP
                ) || (
                    $stopStrategy === HedgeOppositeStopCreate::ONLY_BIG_SL_AFTER_FIRST_POSITION_STOP
                    && $volume >= HedgeOppositeStopCreate::BIG_SL_VOLUME_STARTS_FROM
                )
            ) {
                if ($firstPositionStop = $this->stopRepository->findFirstStopUnderPosition($position)) {
                    $basePrice = $firstPositionStop->getPrice();
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
                $basePrice = $ticker->isIndexAlreadyOverStop($side, $positionPrice) ? $ticker->indexPrice : $positionPrice; // tmp

                $basePrice = $side === Side::Buy ? $basePrice - 25 : $basePrice + 25;
            }

            if ($basePrice) {
                if (!$ticker->isIndexAlreadyOverStop($side, $basePrice)) {
                    $selectedStrategy = $stopStrategy->value . ($hedgeStrategy->description ? ('::' . $hedgeStrategy->description) : '');
                    $triggerPrice = $side === Side::Sell ? $basePrice + 1 : $basePrice - 1;
                } else {
                    $selectedStrategy = 'default (\'cause index price over stop)';
                }
            }
        }

        // If still cannot calc $triggerPrice
        if ($triggerPrice === null) {
            $oppositePriceDelta = $volume >= 0.005 ? self::REGULAR_ORDER_STOP_DISTANCE : self::ADDITION_ORDER_STOP_DISTANCE;

            $triggerPrice = $side === Side::Sell ? $buyOrder->getPrice() + $oppositePriceDelta : $buyOrder->getPrice() - $oppositePriceDelta;
        }

        $stopId = $this->stopService->create($side, $triggerPrice, $volume, self::STOP_ORDER_TRIGGER_DELTA);

        return ['id' => $stopId, 'triggerPrice' => $triggerPrice, 'strategy' => $selectedStrategy];
    }

    /**
     * To not make extra queries to Exchange (what can lead to a ban due to ApiRateLimitReached)
     */
    private function canAffordBuy(Ticker $ticker): bool
    {
        $refreshSeconds = 5;

        if (
            $this->cannotAffordAt !== null
            && ($this->clock->now()->getTimestamp() - $this->cannotAffordAt->getTimestamp()) > $refreshSeconds
        ) {
           return true;
        }

        if ($this->cannotAffordAtPrice === null) {
            return true;
        }

        $range = [$this->cannotAffordAtPrice - 15, $this->cannotAffordAtPrice + 15];

        return !($ticker->indexPrice > $range[0] && $ticker->indexPrice < $range[1]);
    }
}
