<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\FloatHelper;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\Trade\TickerOverConditionalOrderTriggerPrice;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use Doctrine\ORM\QueryBuilder as QB;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsCommonCasesTest */
/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsCornerCasesTest */
/** @see \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\TakeProfit */
#[AsMessageHandler]
final class PushStopsHandler extends AbstractOrdersPusher
{
    public const LIQUIDATION_WARNING_DELTA = 50;
    public const LIQUIDATION_CRITICAL_DELTA = 35;

    public const PRICE_MODIFIER_IF_CURRENT_PRICE_OVER_STOP = 15;
    public const TD_MODIFIER_IF_CURRENT_PRICE_OVER_STOP = 7;

    private const SL_DEFAULT_TRIGGER_DELTA = 25;

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
        $deltaToLiquidation = $position->priceDeltaToLiquidation($ticker);

        if ($deltaToLiquidation <= self::LIQUIDATION_WARNING_DELTA) {
            $triggerBy = TriggerBy::MarkPrice;  $currentPrice = $ticker->markPrice;
        } else {
            $triggerBy = TriggerBy::IndexPrice; $currentPrice = $ticker->indexPrice;
        }

        foreach ($stops as $stop) {
            ### TP
            if ($stop->isTakeProfitOrder()) {
                if ($ticker->lastPrice->isPriceOverTakeProfit($side, $stop->getPrice())) {
                    $this->pushStopToExchange($ticker, $stop, static fn() => $orderService->closeByMarket($position, $stop->getVolume()));
                }
                continue;
            }

            ### Regular
            $td = $this->getStopTriggerDelta($stop);
            $stopPrice = $stop->getPrice();

            if (($currentPriceOverStop = $currentPrice->isPriceOverStop($side, $stopPrice)) || (abs($stopPrice - $currentPrice->value()) <= $td)) {
                $callback = null;
                if ($stop->isCloseByMarketContextSet()) {
                    $callback = static function () use ($orderService, $position, $stop, &$stopsClosedByMarket) {
                        $orderId = $orderService->closeByMarket($position, $stop->getVolume());
                        $stopsClosedByMarket[] = new ExchangeOrder($position->symbol, $stop->getVolume(), Price::float($stop->getPrice()));

                        return $orderId;
                    };
                } elseif ($currentPriceOverStop) {
                    if ($deltaToLiquidation <= self::LIQUIDATION_CRITICAL_DELTA) {
                        $callback = static fn() => $orderService->closeByMarket($position, $stop->getVolume());
                    } else {
                        $newPrice = $side->isShort() ? $currentPrice->value() + self::PRICE_MODIFIER_IF_CURRENT_PRICE_OVER_STOP : $currentPrice->value() - self::PRICE_MODIFIER_IF_CURRENT_PRICE_OVER_STOP;
                        $stop->setPrice($newPrice)->setTriggerDelta($td + self::TD_MODIFIER_IF_CURRENT_PRICE_OVER_STOP);
                    }
                }

                $this->pushStopToExchange($ticker, $stop, $callback ?: static function() use ($positionService, $orderService, $position, $stop, $triggerBy) {
                    try {
                        return $positionService->addConditionalStop($position, $stop->getPrice(), $stop->getVolume(), $triggerBy);
                    } catch (TickerOverConditionalOrderTriggerPrice $e) {
                        return $orderService->closeByMarket($position, $stop->getVolume());
                    }
                });
            }
        }

        if ($stopsClosedByMarket) {
            $loss = 0;
            foreach ($stopsClosedByMarket as $order) {
                $expectedLoss = PnlHelper::getPnlInUsdt($position, $order->getPrice(), $order->getVolume());
                if ($expectedLoss < 0) {
                    $loss += -$expectedLoss;
                }
            }

            if ($loss > 0) {
                try {
                    $this->exchangeAccountService->interTransferFromSpotToContract($position->symbol->associatedCoin(), FloatHelper::round($loss, 3));
                } catch (Throwable) {}
            }
        }
    }

    private function pushStopToExchange(Ticker $ticker, Stop $stop, callable $pushStopCallback): void
    {
        try {
            $stopOrderId = $pushStopCallback();
            $stop->wasPushedToExchange($stopOrderId);
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

    private function getStopTriggerDelta(Stop $stop): float
    {
        if ($stop->isCloseByMarketContextSet()) {
            return 0.3;
        }

        return $stop->getTriggerDelta() ?: self::SL_DEFAULT_TRIGGER_DELTA;
    }

    /**
     * @return Stop[]
     */
    private function findStops(Side $side, Symbol $symbol): array
    {
        return $this->repository->findActive(
            side: $side,
            nearTicker: $this->exchangeService->ticker($symbol),
            qbModifier: static fn(QB $qb) => QueryHelper::addOrder($qb, 'price', $side->isShort() ? 'ASC' : 'DESC')
        );
    }

    public function __construct(
        private readonly StopRepository $repository,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly OrderServiceInterface $orderService,

        private readonly MessageBusInterface $messageBus,
        ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        LoggerInterface $logger,
        ClockInterface $clock,
    ) {
        parent::__construct($exchangeService, $positionService, $clock, $logger);
    }
}
