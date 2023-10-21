<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Hedge\Hedge;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Strategy\StopCreate;
use App\Bot\Domain\Ticker;
use App\Clock\ClockInterface;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Exception\Trade\CannotAffordOrderCost;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use DateTimeImmutable;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function abs;
use function random_int;
use function sprintf;

/** @see PushBtcUsdtShortBuyOrdersTest */
#[AsMessageHandler]
final class PushBuyOrdersHandler extends AbstractOrdersPusher
{
    private const STOP_ORDER_TRIGGER_DELTA = 37;

    private ?DateTimeImmutable $cannotAffordAt = null;
    private ?float $cannotAffordAtPrice = null;

    public function __invoke(PushBuyOrders $message): void
    {
        $position = $this->positionService->getPosition($message->symbol, $message->side);
        if (!$position && $message->side->isLong()) {
            // Fake position
            $ticker = $this->exchangeService->ticker($message->symbol);
            $position = new Position($message->side, $message->symbol, $ticker->indexPrice, 0.05, 1000, 0, 13, 100);
        }

        $side = $position->side;
        $ticker = $this->exchangeService->ticker($message->symbol);

        if (!$this->canAffordBuy($ticker)) {
            return;
        }
        $this->cannotAffordAtPrice = null;
        $this->cannotAffordAt = null;

        $orders = $this->buyOrderRepository->findActiveInRange(
            side: $side,
            from: ($position->isShort() ? $ticker->indexPrice - 15  : $ticker->indexPrice - 20),
            to: ($position->isShort() ? $ticker->indexPrice + 20 : $ticker->indexPrice + 15),
            // To get the cheapest orders (to ignore sleep by CannotAffordOrderCost in case of can afford buy less qty)
            qbModifier: static fn (QueryBuilder $qb) => $qb->addOrderBy($qb->getRootAliases()[0] . '.volume', 'asc')->addOrderBy($qb->getRootAliases()[0] . '.price', $side->isShort() ? 'desc' : 'asc')
        );

        try {
            foreach ($orders as $order) {
                if ($order->mustBeExecuted($ticker)) {
                    $this->buy($position, $ticker, $order);
                }
            }
        } catch (CannotAffordOrderCost $e) {
            $this->logWarning($e, false);

            $this->cannotAffordAtPrice = $ticker->indexPrice;
            $this->cannotAffordAt = $this->clock->now();
//            if ($isHedge = (($oppositePosition = $this->positionService->getOppositePosition($position)) !== null)) {
//                $hedge = Hedge::create($position, $oppositePosition);
//                if ($hedge->isSupportPosition($position) && $hedge->needIncreaseSupport()) {
//                    $this->messageBus->dispatch(
//                        new IncreaseHedgeSupportPositionByGetProfitFromMain($e->symbol, $e->side, $e->qty)
//                    );
//                }
//                // elseif ($hedge->isMainPosition($position)) @todo придумать логику по восстановлению убытков главной позиции
//                // если $this->hedgeService->createStopIncrementalGridBySupport($hedge, $stop) (@see PushStopsHandler) окажется неработоспособной
//                // например, если на момент проверки ещё нужно было держать объём саппорта и сервис не был вызван
//            }
        }
    }

    /**
     * @throws CannotAffordOrderCost
     */
    private function buy(Position $position, Ticker $ticker, BuyOrder $order): void
    {
        try {
            $exchangeOrderId = $this->positionService->marketBuy($position, $ticker, $order->getPrice(), $order->getVolume());

            if ($exchangeOrderId) {
                $order->setExchangeOrderId($exchangeOrderId);

                if ($order->getVolume() <= 0.005) {
                    $this->buyOrderRepository->remove($order);
                }
//                $this->events->dispatch(new BuyOrderPushedToExchange($order));

                if ($order->isWithOppositeOrder()) {
                    $this->createStop($position, $ticker, $order);
                }
            }
        } catch (ApiRateLimitReached $e) {
            $this->logWarning($e);
            $this->sleep($e->getMessage());
        } catch (UnknownByBitApiErrorException|UnexpectedApiErrorException $e) {
            $this->logCritical($e);
        } finally {
            $this->buyOrderRepository->save($order);
        }
    }

    /**
     * @return array{id: int, triggerPrice: float, strategy: StopCreate, description: string}
     */
    private function createStop(Position $position, Ticker $ticker, BuyOrder $buyOrder): array
    {
        $triggerPrice = null;
        $side = $position->side;
        $volume = $buyOrder->getVolume();

        $strategy = $this->getStopStrategy($position, $buyOrder, $ticker);

        $stopStrategy = $strategy['strategy'];
        $description = $strategy['description'];

        $basePrice = null;
        if ($stopStrategy === StopCreate::AFTER_FIRST_POSITION_STOP) {
            if ($firstPositionStop = $this->stopRepository->findFirstPositionStop($position)) {
                $basePrice = $firstPositionStop->getPrice();
            }
        } elseif (
            (
                $stopStrategy === StopCreate::AFTER_FIRST_STOP_UNDER_POSITION
            ) || (
                $stopStrategy === StopCreate::ONLY_BIG_SL_AFTER_FIRST_STOP_UNDER_POSITION
                && $volume >= StopCreate::BIG_SL_VOLUME_STARTS_FROM
            )
        ) {
            if ($firstStopUnderPosition = $this->stopRepository->findFirstStopUnderPosition($position)) {
                $basePrice = $firstStopUnderPosition->getPrice();
            }
        } elseif (
            (
                $stopStrategy === StopCreate::UNDER_POSITION
            ) || (
                $stopStrategy === StopCreate::ONLY_BIG_SL_UNDER_POSITION
                && $volume >= StopCreate::BIG_SL_VOLUME_STARTS_FROM
            )
        ) {
            $positionPrice = \ceil($position->entryPrice);
            if ($ticker->isIndexAlreadyOverStop($side, $positionPrice)) {
                $basePrice = $side->isLong() ? $ticker->indexPrice - 15 : $ticker->indexPrice + 15;
            } else {
                $basePrice = $side->isLong() ? $positionPrice - 15 : $positionPrice + 15;
                $basePrice += random_int(-15, 15);
            }
        } elseif ($stopStrategy === StopCreate::SHORT_STOP) {
            $stopPriceDelta = 20 + random_int(1, 25);
            $triggerPrice = $side->isShort() ? $buyOrder->getPrice() + $stopPriceDelta : $buyOrder->getPrice() - $stopPriceDelta;
        }

        if ($basePrice) {
            if (!$ticker->isIndexAlreadyOverStop($side, $basePrice)) {
                $triggerPrice = $side === Side::Sell ? $basePrice + 1 : $basePrice - 1;
            } else {
                $description = 'because index price over stop)';
            }
        }

        // If still cannot get best $triggerPrice
        if ($stopStrategy !== StopCreate::DEFAULT && $triggerPrice === null) {
            $stopStrategy = StopCreate::DEFAULT;
        }

        if ($stopStrategy === StopCreate::DEFAULT) {
            $stopPriceDelta = StopCreate::getDefaultStrategyStopOrderDistance($volume);

            $triggerPrice = $side === Side::Sell ? $buyOrder->getPrice() + $stopPriceDelta : $buyOrder->getPrice() - $stopPriceDelta;
        }

        $stopId = $this->stopService->create($side, $triggerPrice, $volume, self::STOP_ORDER_TRIGGER_DELTA);

        return ['id' => $stopId, 'triggerPrice' => $triggerPrice, 'strategy' => $stopStrategy, 'description' => $description];
    }

    /**
     * @return array{strategy: StopCreate, description: string}
     */
    private function getStopStrategy(Position $position, BuyOrder $order, Ticker $ticker): array
    {
        $isHedge = ($oppositePosition = $this->positionService->getOppositePosition($position)) !== null;
        if ($isHedge) {
            $hedge = Hedge::create($position, $oppositePosition);
            $hedgeStrategy = $hedge->getHedgeStrategy();

            return [
                'strategy' => $hedge->isSupportPosition($position) ? $hedgeStrategy->supportPositionOppositeStopCreation : $hedgeStrategy->mainPositionOppositeStopCreation,
                'description' => $hedgeStrategy->description
            ];
        }

        $delta = $position->getDeltaWithTicker($ticker);

        $orderVolume = $order->getVolume();

        $defaultStrategyStopPriceDelta = StopCreate::getDefaultStrategyStopOrderDistance($orderVolume);

        // if without hedge?
//        if (($delta < 0) && (abs($delta) >= $defaultStrategyStopPriceDelta)) {
//            return [
//                'strategy' => StopCreate::SHORT_STOP,
//                'description' => 'position in loss'
//            ];
//        }

        if ($order->isWithShortStop()) {
            return [
                'strategy' => StopCreate::SHORT_STOP,
                'description' => \sprintf('delta=%.2f -> to reduce added by mistake on start', $delta)
            ];
        }
//        $isAdd = $orderVolume <= 0.002; if ($isAdd && (($delta >= 400 && $delta < 500) || ($delta >= 600 && $delta < 800) || ($delta >= 1000 && $delta < 1200))) return ['strategy' => StopCreate::SHORT_STOP, 'description' => \sprintf('delta=%.2f -> to reduce added by mistake on start', $delta)];

        // @todo Нужен какой-то определятор состояния трейда
        // if ($delta >= 2500) {return ['strategy' => StopCreate::AFTER_FIRST_POSITION_STOP, 'description' => \sprintf('delta=%.2f -> to reduce added by mistake on long distance', $delta)];}
        if ($delta >= 1500) {
            return [
                'strategy' => StopCreate::AFTER_FIRST_STOP_UNDER_POSITION,
                'description' => \sprintf('delta=%.2f -> increase position size', $delta)
            ];
        }

        if ($delta >= 500) {
            return [
                'strategy' => StopCreate::UNDER_POSITION,
                'description' => \sprintf('delta=%.2f -> to reduce added by mistake on start', $delta)
            ];
        }

        // To not reduce position size by placing stop orders between position and ticker
        if ($delta > (2 * $defaultStrategyStopPriceDelta)) {
            return [
                'strategy' => StopCreate::AFTER_FIRST_STOP_UNDER_POSITION,
                'description' => \sprintf('delta=%.2f -> keep position size on start', $delta)
            ];
        }
        if ($delta > $defaultStrategyStopPriceDelta) {
            return [
                'strategy' => StopCreate::UNDER_POSITION,
                'description' => \sprintf('delta=%.2f -> keep position size on start', $delta)
            ];
        }

        return [
            'strategy' => StopCreate::DEFAULT,
            'description' => 'by default',
        ];
    }

    /**
     * To not make extra queries to Exchange (what can lead to a ban due to ApiRateLimitReached)
     */
    private function canAffordBuy(Ticker $ticker): bool
    {
        $refreshSeconds = 8;

        if (
            $this->cannotAffordAt !== null
            && ($this->clock->now()->getTimestamp() - $this->cannotAffordAt->getTimestamp()) >= $refreshSeconds
        ) {
            return true;
        }

        if ($this->cannotAffordAtPrice === null) {
            return true;
        }

        $range = [$this->cannotAffordAtPrice - 15, $this->cannotAffordAtPrice + 15];

        return !($ticker->indexPrice > $range[0] && $ticker->indexPrice < $range[1]);
    }

    public function __construct(
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly StopRepository $stopRepository,
        private readonly StopService $stopService,

        ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        LoggerInterface $logger,
        ClockInterface $clock,
    ) {
        if ($_ENV['APP_ENV'] !== 'test') {
            var_dump(get_class($exchangeService));
            var_dump(get_class($positionService));
        }

        parent::__construct($exchangeService, $positionService, $clock, $logger);
    }
}
