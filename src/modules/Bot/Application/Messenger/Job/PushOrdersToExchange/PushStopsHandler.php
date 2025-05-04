<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

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
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\StopChecksChain;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use Doctrine\ORM\QueryBuilder as QB;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

use function abs;

/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsCommonCasesTest */
/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsCornerCasesTest */
/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\TakeProfit\PushTakeProfitOrdersTest */
#[AsMessageHandler]
final class PushStopsHandler extends AbstractOrdersPusher
{
    // @todo | need to review (based on other values through handling)
    public const LIQUIDATION_WARNING_DISTANCE_PNL_PERCENT = 18;
    public const LIQUIDATION_CRITICAL_DISTANCE_PNL_PERCENT = 10;

    public function __invoke(PushStops $message): void
    {
        $positionService = $this->positionService; $orderService = $this->orderService;
        $side = $message->side; $symbol = $message->symbol;
        $stopsClosedByMarket = []; /** @var ExchangeOrder[] $stopsClosedByMarket */

        if (!($position = $this->positionService->getPosition($symbol, $side))) {
            return;
        }

        $stops = $this->findStops($side, $symbol);
        $ticker = $this->exchangeService->ticker($symbol); // If ticker changed while get stops
        $distanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);

        $liquidationWarningDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::LIQUIDATION_WARNING_DISTANCE_PNL_PERCENT, $ticker->markPrice);
        if ($distanceWithLiquidation <= $liquidationWarningDistance) {
            $triggerBy = TriggerBy::MarkPrice;  $currentPrice = $ticker->markPrice;
        } else {
            $triggerBy = TriggerBy::IndexPrice; $currentPrice = $ticker->indexPrice;
        }
        $liquidationCriticalDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::LIQUIDATION_CRITICAL_DISTANCE_PNL_PERCENT, $currentPrice);

        $checksContext = TradingCheckContext::withCurrentPositionState($ticker, $position);
        foreach ($stops as $stop) {
            ### TakeProfit ###
            if ($stop->isTakeProfitOrder()) {
                if ($ticker->lastPrice->isPriceOverTakeProfit($side, $stop->getPrice())) {
                    $this->pushStopToExchange($ticker, $stop, $checksContext, static fn() => $orderService->closeByMarket($position, $stop->getVolume()));
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
                $stopMustBePushedByTriggerDelta = abs($stopPrice - $currentPrice->value()) <= $stop->getTriggerDelta();
                if ((!$currentPriceOverStop && !$stopMustBePushedByTriggerDelta) || !$this->stopCanBePushed($stop, $checksContext)) continue;

                if ($currentPriceOverStop) {
                    if ($distanceWithLiquidation <= $liquidationCriticalDistance) {
                        $callback = self::closeByMarketCallback($orderService, $position, $stop, $stopsClosedByMarket);
                    } else {
                        $additionalTriggerDelta = StopHelper::additionalTriggerDeltaIfCurrentPriceOverStop($symbol);
                        $priceModifierIfCurrentPriceOverStop = StopHelper::priceModifierIfCurrentPriceOverStop($currentPrice);

                        $newPrice = $side->isShort() ? $currentPrice->value() + $priceModifierIfCurrentPriceOverStop : $currentPrice->value() - $priceModifierIfCurrentPriceOverStop;
                        $stop->setPrice($newPrice)->increaseTriggerDelta($additionalTriggerDelta);
                    }
                }
            }

            $this->pushStopToExchange($ticker, $stop, $checksContext, $callback ?: static function() use ($positionService, $orderService, $position, $stop, $triggerBy) {
                try {
                    return $positionService->addConditionalStop($position, $stop->getPrice(), $stop->getVolume(), $triggerBy);
                } catch (Throwable) {
                    return $orderService->closeByMarket($position, $stop->getVolume());
                }
            });
        }

        $stopsClosedByMarket && $this->processOrdersClosedByMarket($position, $stopsClosedByMarket);
    }

    private static function closeByMarketCallback(OrderServiceInterface $orderService, Position $position, Stop $stop, array &$stopsClosedByMarket): callable
    {
        return static function () use ($orderService, $position, $stop, &$stopsClosedByMarket) {
            $exchangeOrderId = $orderService->closeByMarket($position, $stop->getVolume());
            $stopsClosedByMarket[] = new ExchangeOrder($position->symbol, $stop->getVolume(), $stop->getPrice());

            return $exchangeOrderId;
        };
    }

    private function stopCanBePushed(Stop $stop, TradingCheckContext $checksContext): bool
    {
        $checkResult = $this->checks->check($stop, $checksContext);

        !$checkResult->quiet && OutputHelper::warning($checkResult->info());

        return $checkResult->success;
    }

    private function pushStopToExchange(Ticker $ticker, Stop $stop, TradingCheckContext $checksContext, callable $pushStopCallback): void
    {
        try {
            $exchangeOrderId = $pushStopCallback();
            $stop->wasPushedToExchange($exchangeOrderId);
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
     * @return Stop[]
     */
    private function findStops(Side $side, Symbol $symbol): array
    {
        return $this->repository->findActive(
            symbol: $symbol,
            side: $side,
            nearTicker: $this->exchangeService->ticker($symbol),
            qbModifier: static fn(QB $qb) => QueryHelper::addOrder($qb, 'price', $side->isShort() ? 'ASC' : 'DESC'),
        );
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

        private readonly MessageBusInterface $messageBus,
        private readonly StopChecksChain $checks,
        ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        LoggerInterface $appErrorLogger,
        ClockInterface $clock,
    ) {
        parent::__construct($exchangeService, $positionService, $clock, $appErrorLogger);
    }
}
