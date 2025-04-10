<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Application\Messenger\Trading\CoverLossesAfterCloseByMarket\CoverLossesAfterCloseByMarketConsumerDto;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
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
use Doctrine\ORM\QueryBuilder as QB;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;


use function abs;
use function sprintf;

/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsCommonCasesTest */
/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsCornerCasesTest */
/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\TakeProfit\PushTakeProfitOrdersTest */
#[AsMessageHandler]
final class PushStopsHandler extends AbstractOrdersPusher
{
    // @todo | need to review (based on other values through handling)
    public const LIQUIDATION_WARNING_DISTANCE_PNL_PERCENT = 18;
    public const LIQUIDATION_CRITICAL_DISTANCE_PNL_PERCENT = 10;
    private const SAFE_LIQUIDATION_DISTANCE_FOR_MAIN_POSITION_AFTER_CLOSE_SUPPORT = 20000;

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

        foreach ($stops as $stop) {
            ### TakeProfit ###
            if ($stop->isTakeProfitOrder()) {
                if ($ticker->lastPrice->isPriceOverTakeProfit($side, $stop->getPrice())) {
                    $this->pushStopToExchange($ticker, $stop, static fn() => $orderService->closeByMarket($position, $stop->getVolume()));
                }
                continue;
            }

            ### Regular ###
            $stopPrice = $stop->getPrice();
            $currentPriceOverStop = $currentPrice->isPriceOverStop($side, $stopPrice);

            $callback = null;
            if ($stop->isCloseByMarketContextSet()) {
                # @todo | move to some separated service
                if ($position->symbol === Symbol::BTCUSDT && $position->isSupportPosition()) {
                    if (!$this->checkCanCloseSupportWhilePushStopsThrottlingLimiter->create((string)$stop->getId())->consume()->isAccepted()) {
                        continue;
                    }
                    $sandbox = $this->sandboxFactory->byCurrentState($symbol);
                    try {
                        $sandbox->processOrders(SandboxStopOrder::fromStop($stop));
                    } catch (Throwable $e) {
                        $this->logger->critical(
                            sprintf('[PushStopsHandler] Got error "%s" when try to handle %d in sandbox', $e->getMessage(), $stop->getId())
                        );
                    }
                    $newState = $sandbox->getCurrentState();
                    $mainPosition = $newState->getPosition($position->side->getOpposite());
                    $safePriceDistance = self::SAFE_LIQUIDATION_DISTANCE_FOR_MAIN_POSITION_AFTER_CLOSE_SUPPORT;
                    $isLiquidationOnSafeDistance = $mainPosition->side->isShort()
                        ? $mainPosition->liquidationPrice()->sub($safePriceDistance)->greaterOrEquals($ticker->markPrice)
                        : $mainPosition->liquidationPrice()->add($safePriceDistance)->lessOrEquals($ticker->markPrice);

                    if (!$isLiquidationOnSafeDistance) {
                        OutputHelper::warning(sprintf('[%d] Skip stop %s|%s on %s.', $stop->getId(), $stop->getVolume(), $stop->getPrice(), $position));
                        continue;
                    }
                }

                if (!$currentPriceOverStop) continue;
                $callback = static function () use ($orderService, $position, $stop, &$stopsClosedByMarket) {
                    $orderId = $orderService->closeByMarket($position, $stop->getVolume());
                    $stopsClosedByMarket[] = new ExchangeOrder($position->symbol, $stop->getVolume(), $stop->getPrice());

                    return $orderId;
                };
            } else {
                $stopMustBePushedByTriggerDelta = abs($stopPrice - $currentPrice->value()) <= $stop->getTriggerDelta();
                if (!$currentPriceOverStop && !$stopMustBePushedByTriggerDelta) {
                    continue;
                }

                if ($currentPriceOverStop) {
                    if ($distanceWithLiquidation <= $liquidationCriticalDistance) {
                        $callback = static fn() => $orderService->closeByMarket($position, $stop->getVolume());
                    } else {
                        $additionalTriggerDelta = StopHelper::additionalTriggerDeltaIfCurrentPriceOverStop($symbol);
                        $priceModifierIfCurrentPriceOverStop = StopHelper::priceModifierIfCurrentPriceOverStop($currentPrice);

                        $newPrice = $side->isShort() ? $currentPrice->value() + $priceModifierIfCurrentPriceOverStop : $currentPrice->value() - $priceModifierIfCurrentPriceOverStop;
                        $stop->setPrice($newPrice)->increaseTriggerDelta($additionalTriggerDelta);
                    }
                }
            }

            $this->pushStopToExchange($ticker, $stop, $callback ?: static function() use ($positionService, $orderService, $position, $stop, $triggerBy) {
                try {
                    return $positionService->addConditionalStop($position, $stop->getPrice(), $stop->getVolume(), $triggerBy);
                } catch (Throwable $e) {
                    return $orderService->closeByMarket($position, $stop->getVolume());
                }
            });
        }

        $stopsClosedByMarket && $this->processOrdersClosedByMarket($position, $stopsClosedByMarket);
    }

    private function pushStopToExchange(Ticker $ticker, Stop $stop, callable $pushStopCallback): void
    {
        try {
            $exchangeOrderId = $pushStopCallback();
            $stop->wasPushedToExchange($exchangeOrderId);
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
        private TradingSandboxFactoryInterface $sandboxFactory,
        private readonly RateLimiterFactory $checkCanCloseSupportWhilePushStopsThrottlingLimiter,
        ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        LoggerInterface $appErrorLogger,
        ClockInterface $clock,
    ) {
        parent::__construct($exchangeService, $positionService, $clock, $appErrorLogger);
    }
}
