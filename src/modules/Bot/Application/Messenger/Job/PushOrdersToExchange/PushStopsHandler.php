<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParameters;
use App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumerDto;
use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Helper\StopHelper;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Settings\Enum\PriceRangeLeadingToUseMarkPriceOptions;
use App\Bot\Application\Settings\PushStopSettingsWrapper;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
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
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\StopChecksChain;
use App\Trading\Domain\Symbol\SymbolInterface;
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
        // @todo | stop check mainPosition stops first

        $positionService = $this->positionService; $orderService = $this->orderService;
        $side = $message->side; $symbol = $message->symbol;
        $stopsClosedByMarket = []; /** @var ExchangeOrder[] $stopsClosedByMarket */

        $position = $message->positionState ?? $this->positionService->getPosition($symbol, $side);
        if (!$position) {
            return;
        }

        $stops = $this->findStops($side, $symbol);
        $ticker = $this->exchangeService->ticker($symbol); // If ticker changed while get stops
//        if ($message->dispatchedAt && $tickerDto->cacheSavedAt) {
//            $diff = $message->dispatchedAt - $tickerDto->cacheSavedAt;
//            $updatedByWorker = $tickerDto->updatedByWorker;
//            if ($updatedByWorker !== AppContext::runningWorker()) OutputHelper::print(sprintf('%s ticker cache lifetime = %s', $symbol->name(), $tickerDto->lifetime($message->dispatchedAt)));
//            // @todo compare with markPrice from all positions
//            if ($diff < 0 && $updatedByWorker !== AppContext::runningWorker()) OutputHelper::warning(sprintf('negative diff: %s (by %s)', $diff, $updatedByWorker->value));
//            $message->profilingContext && $message->profilingContext->registerNewPoint(sprintf('%s: "%s" ticker diff === %s (cache created by %s)', OutputHelper::shortClassName($this), $symbol->name(), $diff, $updatedByWorker->value));
//        }

        $liquidationParameters = $this->liquidationDynamicParameters($position, $ticker);
        $distanceToUseMarkPrice = $this->pushStopSettings->rangeToUseWhileChooseMarkPriceAsTriggerPrice($position) === PriceRangeLeadingToUseMarkPriceOptions::WarningRange
            ? $liquidationParameters->warningDistanceRaw()
            : $liquidationParameters->criticalDistance();

        $distanceWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
        if ($distanceWithLiquidation <= $distanceToUseMarkPrice) {
            $triggerBy = TriggerBy::MarkPrice;  $currentPrice = $ticker->markPrice;
            // @todo | pushStops | max (for short and min for long) between index and mark
            // + select $triggerBy based on selected price
        } else {
            $triggerBy = TriggerBy::IndexPrice; $currentPrice = $ticker->indexPrice;
        }

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
                $stopMustBePushedByTriggerDelta = $currentPrice->deltaWith($stopPrice, round: false) <= $stop->getTriggerDelta();
                if ((!$currentPriceOverStop && !$stopMustBePushedByTriggerDelta) || !$this->stopCanBePushed($stop, $checksContext)) continue;

                if ($currentPriceOverStop) {
                    if ($distanceWithLiquidation <= $liquidationParameters->criticalDistance()) {
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

    private function pushStopToExchange(Ticker $ticker, Stop $stop, TradingCheckContext $checksContext, callable $pushStopCallback): void
    {
        try {
            $exchangeOrderId = $pushStopCallback();
            $stop->wasPushedToExchange($exchangeOrderId);
            // @todo manual release events
            // or check what might happen in case of some exception
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
    private function findStops(Side $side, SymbolInterface $symbol): array
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

    private function liquidationDynamicParameters(Position $position, Ticker $ticker): LiquidationDynamicParameters
    {
        return new LiquidationDynamicParameters(settingsProvider: $this->settingsProvider, position: $position, ticker: $ticker);
    }

    public function __construct(
        private readonly StopRepository $repository,
        private readonly OrderServiceInterface $orderService,
        private readonly AppSettingsProviderInterface $settingsProvider,
        private readonly PushStopSettingsWrapper $pushStopSettings,

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
