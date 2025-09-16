<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParametersFactoryInterface;
use App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumerDto;
use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Helper\StopHelper;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Clock\ClockInterface;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\StopChecksChain;
use App\Stop\Application\UseCase\PushStopsToTexchange\PushStopsDP;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use Doctrine\ORM\QueryBuilder as QB;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsCommonCasesTest */
/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsCornerCasesTest */
/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\TakeProfit\PushTakeProfitOrdersTest */
#[AsMessageHandler]
final class PushStopsHandler extends AbstractOrdersPusher
{
    public function __invoke(PushStops $message): void
    {
        $positionService = $this->positionService; $orderService = $this->orderService;
        $side = $message->side; $symbol = $message->symbol;
        $stopsClosedByMarket = []; /** @var ExchangeOrder[] $stopsClosedByMarket */

        $position = $message->positionState ?? $this->positionService->getPosition($symbol, $side);
        if (!$position) {
            return;
        }

        $ticker = $this->exchangeService->ticker($symbol);

        $parameters = new PushStopsDP($this->liquidationDynamicParametersFactory, $position, $ticker);
        $triggerBy = $parameters->priceToUseWhenPushStopsToExchange();
        $currentPrice = match ($triggerBy) {
            TriggerBy::MarkPrice => $ticker->markPrice,
            TriggerBy::IndexPrice => $ticker->indexPrice,
            TriggerBy::LastPrice => $ticker->lastPrice,
        };

        $sort = static fn(QB $qb) => QueryHelper::addOrder($qb, 'price', $side->isShort() ? 'ASC' : 'DESC');
        $stops = $this->repository->findActiveForPush($symbol, $side, $currentPrice, qbModifier: $sort);

        // @todo | what if ticker changed while get stops?
        $distanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
        $criticalDistance = $parameters->criticalDistance();

        $checksContext = TradingCheckContext::withCurrentPositionState($ticker, $position);
        foreach ($stops as $stop) {
            ### TakeProfit ###
            if ($stop->isTakeProfitOrder()) {
                if ($ticker->lastPrice->isPriceOverTakeProfit($side, $stop->getPrice())) {
                    $this->pushStopToExchange($position, $ticker, $stop, $checksContext, static fn() => $orderService->closeByMarket($position, $stop->getVolume())->exchangeOrderId);
                }
                continue;
            }

            ### Regular ###
            $stopPrice = $stop->getPrice();
            $currentPriceOverStop = $currentPrice->isPriceOverStop($side, $stopPrice);

            $callback = null;
            if ($stop->isCloseByMarketContextSet()) {
                if (!$currentPriceOverStop || !$this->stopCanBePushed($stop, $checksContext)) continue;
                $callback = self::closeByMarketCallback($orderService, $position, $stop, $stopsClosedByMarket);
            } else {
                $stopMustBePushedByTriggerDelta = $currentPrice->deltaWith($stopPrice, round: false) <= $stop->getTriggerDelta();
                if ((!$currentPriceOverStop && !$stopMustBePushedByTriggerDelta) || !$this->stopCanBePushed($stop, $checksContext)) continue;

                if ($currentPriceOverStop) {
                    if ($distanceWithLiquidation <= $criticalDistance) {
                        $callback = self::closeByMarketCallback($orderService, $position, $stop, $stopsClosedByMarket);
                    } else {
                        $additionalTriggerDelta = StopHelper::additionalTriggerDeltaIfCurrentPriceOverStop($symbol);
                        $priceModifierIfCurrentPriceOverStop = StopHelper::priceModifierIfCurrentPriceOverStop($currentPrice);

                        $newPrice = $currentPrice->modifyByDirection($position->side, PriceMovementDirection::TO_LOSS, $priceModifierIfCurrentPriceOverStop, zeroSafe: true);
                        $stop->setPrice($newPrice->value())->increaseTriggerDelta($additionalTriggerDelta);
                    }
                }
            }

            $this->pushStopToExchange($position, $ticker, $stop, $checksContext, $callback ?: static function() use ($positionService, $orderService, $position, $stop, $triggerBy) {
                try {
                    return $positionService->addConditionalStop($position, $stop->getPrice(), $stop->getVolume(), $triggerBy);
                } catch (Throwable) {
                    return $orderService->closeByMarket($position, $stop->getVolume())->exchangeOrderId;
                }
            });
        }

        $stopsClosedByMarket && $this->processOrdersClosedByMarket($position, $stopsClosedByMarket);
    }

    private static function closeByMarketCallback(OrderServiceInterface $orderService, Position $position, Stop $stop, array &$stopsClosedByMarket): callable
    {
        return static function () use ($orderService, $position, $stop, &$stopsClosedByMarket) {
            $result = $orderService->closeByMarket($position, $stop->getVolume());
            $exchangeOrderId = $result->exchangeOrderId;
            $stopsClosedByMarket[] = new ExchangeOrder($position->symbol, $result->realClosedQty, $stop->getPrice());

            return $exchangeOrderId;
        };
    }

    private function stopCanBePushed(Stop $stop, TradingCheckContext $checksContext): bool
    {
        if (!$this->checks) {
            return true;
        }

        $checkResult = $this->checks->check($stop, $checksContext);
        if (!$checkResult->quiet) {
            $checkResult->success && OutputHelper::success($checkResult->info());
            !$checkResult->success && OutputHelper::failed($checkResult->info());
        }

        return $checkResult->success;
    }

    private function pushStopToExchange(Position $prevPositionState, Ticker $ticker, Stop $stop, TradingCheckContext $checksContext, callable $pushStopCallback): void
    {
        try {
            $exchangeOrderId = $pushStopCallback();
            $stop->wasPushedToExchange($exchangeOrderId, $prevPositionState);
            // @todo manual release events or check what might happen in case of some exception
            $checksContext->resetState();
        } catch (ApiRateLimitReached $e) {
            $this->logWarning($e);
            $this->sleep($e->getMessage());
        } catch (MaxActiveCondOrdersQntReached $e) {
            // @todo | currentPriceWithLiquidationPriceDifference->delta() + isCurrentPriceOverStopPrice
            $this->messageBus->dispatch(TryReleaseActiveOrders::forStop($ticker->symbol, $stop));
        } catch (UnknownByBitApiErrorException|UnexpectedApiErrorException $e) {
            $this->logCritical($e);
        } finally {
            $this->repository->save($stop);
        }
    }

    /**
     * @param ExchangeOrder[] $stopsClosedByMarket
     */
    private function processOrdersClosedByMarket(Position $position, array $stopsClosedByMarket): void
    {
        $loss = 0;
        foreach ($stopsClosedByMarket as $order) {
            if (($expectedPnl = PnlHelper::getPnlInUsdt($position, $order->getPrice(), $order->getVolume())) < 0) {
                $loss += -$expectedPnl;
            }
        }

        if ($loss > 0) {
            // @todo | m.b. publish new message for each stop? =)
            $this->messageBus->dispatch(CoverLossesAfterCloseByMarketConsumerDto::forPosition($position, $loss));
        }
    }

    public function __construct(
        private readonly StopRepository $repository,
        private readonly OrderServiceInterface $orderService,
        private readonly LiquidationDynamicParametersFactoryInterface $liquidationDynamicParametersFactory,

        private readonly MessageBusInterface $messageBus,
        ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        LoggerInterface $appErrorLogger,
        ClockInterface $clock,
        private readonly ?StopChecksChain $checks = null,
    ) {
        parent::__construct($exchangeService, $positionService, $clock, $appErrorLogger);
    }
}
