<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Events\Stop\StopPushedToExchange;
use App\Bot\Application\Exception\ApiRateLimitReached;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Hedge\Hedge;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Application\Service\Orders\BuyOrderService;
use App\Bot\Application\Exception\MaxActiveCondOrdersQntReached;
use App\Bot\Application\Service\Orders\StopService;
use App\Clock\ClockInterface;
use App\Helper\Json;
use App\Helper\PriceHelper;
use App\Helper\VolumeHelper;
use App\Worker\AppContext;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class PushRelevantStopsHandler extends AbstractOrdersPusher
{
    private const SL_DEFAULT_TRIGGER_DELTA = 25;
    private const SL_SUPPORT_FROM_MAIN_HEDGE_POSITION_TRIGGER_DELTA = 5;
    private const BUY_ORDER_TRIGGER_DELTA = 1;
    private const BUY_ORDER_OPPOSITE_PRICE_DISTANCE = 37;

    public function __construct(
        private readonly HedgeService $hedgeService,
        private readonly StopRepository $stopRepository,
        private readonly BuyOrderService $buyOrderService,
        private readonly StopService $stopService,
        private readonly MessageBusInterface $messageBus,
        private readonly EventDispatcherInterface $events,
        ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        LoggerInterface $logger,
        ClockInterface $clock,

        private readonly float $slForcedTriggerDelta
    ) {
        parent::__construct($exchangeService, $positionService, $clock, $logger);
    }

    public function __invoke(PushRelevantStopOrders $message): void
    {
        $position = $this->positionService->getPosition($message->symbol, $message->side);
        if (!$position) {
            return;
        }

        $ticker = $this->exchangeService->ticker($message->symbol);

//        \print_r(
//            \sprintf(
//                '%s | %s | %s',
//                (new \DateTimeImmutable())->format('m/d H:i:s.v'),
//                $message->side->value,
//                'ind: ' . $ticker->indexPrice . '; upd: ' . $ticker->updatedBy . '; context: ' . AppContext::workerHash()
//            ) . PHP_EOL
//        );


        /// !!!! @todo Нужно сделать очистку таблиц (context->'exchange.orderId' is not null)

        $stops = $this->stopRepository->findActive($position->side, $ticker);
        $ticker = $this->exchangeService->ticker($message->symbol);

        foreach ($stops as $stop) {
            $td = $stop->getTriggerDelta() ?: self::SL_DEFAULT_TRIGGER_DELTA;
            $price = $stop->getPrice();

            if (
                ($indexAlreadyOverStop = $ticker->isIndexAlreadyOverStop($position->side, $price))
                || (abs($price - $ticker->indexPrice) <= ($this->slForcedTriggerDelta ?: $td))
            ) {
                if ($indexAlreadyOverStop) {
                    $newPrice = $stop->getPositionSide() === Side::Sell ? $ticker->indexPrice + 15 : $ticker->indexPrice - 15;
                    $stop->setPrice($newPrice);
                    $stop->setTriggerDelta($td + 7);
                }

                $this->addStop($position, $ticker, $stop);
            }
        }
    }

    private function addStop(Position $position, Ticker $ticker, Stop $stop): void
    {
        try {
            if ($exchangeOrderId = $this->positionService->addStop($position, $ticker, $stop->getPrice(), $stop->getVolume())) {
                $stop->setExchangeOrderId($exchangeOrderId);

                if (
                    $stop->getVolume() <= 0.005
                    && !$stop->isSupportFromMainHedgePositionStopOrder()
                ) {
                    $this->stopRepository->remove($stop);
                }

//                $this->events->dispatch(new StopPushedToExchange($stop));
                $this->createOpposite($position, $stop, $ticker);

//                $this->info(\sprintf('%sSL%s %.3f | $%.2f (oppositeBuyOrders: %s)', $sign = ($position->side === Side::Sell ? '---' : '+++'), $sign, $stop->getVolume(), $stop->getPrice(), $oppositeBuyOrderData = Json::encode($oppositeBuyOrderData)), ['exchange.orderId' => $exchangeOrderId, 'oppositeBuyOrders' => $oppositeBuyOrderData]);
//                $this->info(\sprintf('%sSL%s', $sign = ($position->side === Side::Sell ? '---' : '+++'), $sign));
            }
        } catch (ApiRateLimitReached $e) {
            $this->logExchangeClientException($e);

            $this->sleep($e->getMessage());
        } catch (MaxActiveCondOrdersQntReached $e) {
            $this->logExchangeClientException($e);

            $this->messageBus->dispatch(
                TryReleaseActiveOrders::forStop($ticker->symbol, $stop)
            );
        } finally {
            $this->stopRepository->save($stop);
        }
    }

    /**
     * @return array<array{id: int, volume: float, price: float}>
     */
    private function createOpposite(Position $position, Stop $stop, Ticker $ticker): array
    {
        // @todo Костыль
        if ($position->size > 1.6) {
            return [];
        }

        $side = $stop->getPositionSide();
        $price = $stop->getPrice(); // $price = $stop->getOriginalPrice() ?? $stop->getPrice();
        $distance = self::BUY_ORDER_OPPOSITE_PRICE_DISTANCE;

        $triggerPrice = $side === Side::Sell ? $price - $distance : $price + $distance;
        $volume = $stop->getVolume() >= 0.006 ? VolumeHelper::round($stop->getVolume() / 3) : $stop->getVolume();

        $isHedge = ($oppositePosition = $this->positionService->getOppositePosition($position)) !== null;
        if ($isHedge) {
            $hedge = Hedge::create($position, $oppositePosition);
            // If this is support position, we need to make sure that we can afford opposite buy after stop (which was added, for example, by mistake)
            if (
                $hedge->isSupportPosition($position)
                && $hedge->needIncreaseSupport()
            ) {
                $vol = VolumeHelper::round($volume / 3);

                if ($vol > 0.005) {
                    $vol = 0.005;
                }

                // @todo Всё это лучше вынести в настройки
                // С человекопонятными названиями

                $this->stopService->create(
                    $oppositePosition->side,
                    $oppositePosition->side === Side::Sell ? ($triggerPrice - 3) : ($triggerPrice + 3),
                    $vol,
                    self::SL_SUPPORT_FROM_MAIN_HEDGE_POSITION_TRIGGER_DELTA,
                    ['asSupportFromMainHedgePosition' => true, 'createdWhen' => 'tryGetHelpFromHandler'],
                );
            } elseif (
                $hedge->isMainPosition($position)
                && $ticker->isIndexAlreadyOverStop($position->side, $position->entryPrice) // MainPosition now in loss
                && !$hedge->needKeepSupportSize()
            ) {
                // @todo Need async job instead (to check $hedge->needKeepSupportSize() in future, if now still need keep support size)
                // Or it can be some problems at runtime...Need async job
                $this->hedgeService->createStopIncrementalGridBySupport($hedge, $stop);
            }
            // @todo Придумать нормульную логику (доделать проверку баланса и необходимость в фиксации main-позиции?)
            // Пока что добавил отлов CannotAffordOrderCost в PushRelevantBuyOrdersHandler при попытке купить
        }

        $context = ['onlyAfterExchangeOrderExecuted' => $stop->getExchangeOrderId()];

        $orders = [
            ['volume' => $volume, 'price' => $triggerPrice]
        ];

        if ($stop->getVolume() >= 0.006) {
            $orders[] = [
                'volume' => VolumeHelper::round($stop->getVolume() / 3.5),
                'price' => PriceHelper::round(
                    $side === Side::Sell ? $triggerPrice - $distance / 2 : $triggerPrice + $distance / 2
                ),
            ];
            $orders[] = [
                'volume' => VolumeHelper::round($stop->getVolume() / 4.5),
                'price' => PriceHelper::round(
                    $side === Side::Sell ? $triggerPrice - $distance / 3.8 : $triggerPrice + $distance / 3.8
                ),
            ];
        }

        foreach ($orders as $order) {
            $order['id'] = $this->buyOrderService->create(
                $side,
                $order['price'],
                $order['volume'],
                self::BUY_ORDER_TRIGGER_DELTA,
                $context,
            );
        }

        return $orders;
    }
}
