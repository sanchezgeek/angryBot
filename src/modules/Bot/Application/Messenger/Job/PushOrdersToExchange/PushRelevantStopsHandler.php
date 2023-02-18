<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
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
use App\Bot\Service\Buy\BuyOrderService;
use App\Bot\Application\Exception\MaxActiveCondOrdersQntReached;
use App\Bot\Service\Stop\StopService;
use App\Clock\ClockInterface;
use App\Helper\VolumeHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class PushRelevantStopsHandler extends AbstractOrdersPusher
{
    private const SL_DEFAULT_TRIGGER_DELTA = 25;
    private const SL_SUPPORT_FROM_MAIN_HEDGE_POSITION_TRIGGER_DELTA = 5;
    private const BUY_ORDER_TRIGGER_DELTA = 1;
    private const BUY_ORDER_OPPOSITE_PRICE_DISTANCE = 21;

    private ?Ticker $lastTicker = null;

    public function __construct(
        private readonly HedgeService $hedgeService,
        private readonly StopRepository $stopRepository,
        private readonly BuyOrderService $buyOrderService,
        private readonly StopService $stopService,
        private readonly MessageBusInterface $messageBus,
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
        $positionData = $this->getPositionData($message->symbol, $message->side, true);
        if (!$positionData->isPositionOpened()) {
            return;
        }


        /// !!!! @todo Нужно сделать очистку таблиц (context->'exchange.orderId' is not null)



        $stops = $this->stopRepository->findActive($positionData->position->side, $this->lastTicker);
        $ticker = $this->exchangeService->getTicker($message->symbol);

        foreach ($stops as $stop) {
            if (
                ($indexAlreadyOverStop = $ticker->isIndexAlreadyOverStop($positionData->position->side, $stop->getPrice()))
                || (
                    abs($stop->getPrice() - $ticker->indexPrice) <= (
                        $this->slForcedTriggerDelta ?: ($stop->getTriggerDelta() ?: self::SL_DEFAULT_TRIGGER_DELTA)
                    )
                )
            ) {
                if ($indexAlreadyOverStop) {
                    $newPrice = $stop->getPositionSide() === Side::Sell ? $ticker->indexPrice + 10 : $ticker->indexPrice - 10;
                    $stop->setPrice($newPrice);
                }

                $this->addStop($positionData->position, $ticker, $stop);
            }
        }

        $this->lastTicker = $ticker;

        $this->info(\sprintf('%s: %.2f', $message->symbol->value, $ticker->indexPrice));
    }

    private function addStop(Position $position, Ticker $ticker, Stop $stop): void
    {
        try {
            if ($exchangeOrderId = $this->positionService->addStop($position, $ticker, $stop->getPrice(), $stop->getVolume())) {
                $stop->setExchangeOrderId($exchangeOrderId);

                // @todo Есть косяк: выше проставляется новый price в расчёте на то, что тут будет ордер на бирже. А его нет. Денег не хватило. Но ниже делается persist. originalPrice попадает в базу. А на самом деле ордер не был отправлен.
                // Нужно новую цену не сразу фигачить в поле, а помещать в контекст и тут уже применять. Если вернулся $exchangeOrderId

                if (
                    $stop->getVolume() <= 0.005
                    && !$stop->isSupportFromMainHedgePositionStopOrder()
                ) {
                    $this->stopRepository->remove($stop);
                } else {
                    $this->stopRepository->save($stop);
                }

                $oppositeBuyOrderData = $this->createOpposite($position, $stop, $ticker);

                $this->info(
                    \sprintf(
                        '%sSL%s %.3f | $%.2f (oppositeBuy: $%.2f)',
                        $sign = ($position->side === Side::Sell ? '---' : '+++'), $sign,
                        $stop->getVolume(),
                        $stop->getPrice(),
                        $oppositeBuyOrderData['triggerPrice'],
                    ),
                    ['exchange.orderId' => $exchangeOrderId, '`buy_order`' => $oppositeBuyOrderData],
                );
            }
        } catch (ApiRateLimitReached $e) {
            $this->logExchangeClientException($e);

            $this->sleep($e->getMessage());
        } catch (MaxActiveCondOrdersQntReached $e) {
            $this->logExchangeClientException($e);

            $this->messageBus->dispatch(
                TryReleaseActiveOrders::forStop($ticker->symbol, $stop)
            );
        }
    }

    /**
     * @return array{id: int, triggerPrice: float}
     */
    private function createOpposite(Position $position, Stop $stop, Ticker $ticker): array
    {
        $price = $stop->getOriginalPrice() ?? $stop->getPrice();
        $triggerPrice = $stop->getPositionSide() === Side::Sell
            ? $price - self::BUY_ORDER_OPPOSITE_PRICE_DISTANCE
            : $price + self::BUY_ORDER_OPPOSITE_PRICE_DISTANCE;

        $volume = $stop->getVolume() >= 0.006 ? VolumeHelper::round($stop->getVolume() / 2) : $stop->getVolume();

        $isHedge = ($oppositePosition = $this->getOppositePosition($position)) !== null;
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

        $orderId = $this->buyOrderService->create(
            $stop->getPositionSide(),
            $triggerPrice,
            $volume,
            self::BUY_ORDER_TRIGGER_DELTA,
            ['onlyAfterExchangeOrderExecuted' => $stop->getExchangeOrderId()],
        );

        return ['id' => $orderId, 'triggerPrice' => $triggerPrice];
    }
}
