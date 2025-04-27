<?php

declare(strict_types=1);

namespace App\Application\EventListener\Stop;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Stop\Event\StopPushedToExchange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Worker\AppContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

use function sprintf;

/**
 * `stop.pushedToExchange` [
 *      1) after supportPosition stop loss
 *      2) for stops closed by market
 *      3) withOppositeOrder
 * ] => cover losses by adding SL on MainPosition.
 */
#[AsEventListener]
final class FixOppositePositionListener
{
    public const ENABLED = true;

    const APPLY_IF_MAIN_POSITION_PNL_GREATER_THAN_DEFAULT = 300;

    /** @var <array-key, int[]> PNL_GREATER_THAN, SUPPLY_STOP_DISTANCE_PCT */
    const CONFIG = [
        Symbol::BTCUSDT->value => [300, 120],
        Symbol::ETHUSDT->value => [500, 200],
        Symbol::FARTCOINUSDT->value => [600, 400],
        'other' => [1000, 300],
    ];

    public function __construct(
        private readonly OrderCostCalculator $orderCostCalculator,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionServiceInterface $positionService, /** @todo | MB without cache? */
        private readonly StopServiceInterface $stopService,
        private readonly ?float $applyIfMainPositionPnlGreaterThan = null, // for tests
    ) {
    }

    public function __invoke(StopPushedToExchange $event): void
    {
        if (!self::ENABLED) {
            return;
        }

        $stop = $event->stop;
        if (!$stop->isCloseByMarketContextSet()) {
            return;
        } // if (!($closedVolume >= $symbol->minOrderQty())) return;
        $stoppedPosition = $this->positionService->getPosition($stop->getSymbol(), $stop->getPositionSide());
        if (!$oppositePosition = $stoppedPosition->oppositePosition) {
            return;
        }

        // @todo | based on support size + current losses? less distance -> simple to cover => support will not be affected much
        if (!(
             ($oppositePosition->isMainPosition() && $stop->isFixOppositeMainOnLossEnabled())
             || ($oppositePosition->isSupportPosition() && $stop->isFixOppositeSupportOnLossEnabled())
        )) {
            return;
        }

        $symbol = $stop->getSymbol();
        $stopPrice = $stop->getPrice();
        $closedVolume = $stop->getVolume();

        $ticker = $this->exchangeService->ticker($symbol);
        if (!$stoppedPosition->isPositionInLoss($ticker->lastPrice)) {
            return;
        } // if ($this->hedgeService->isSupportSizeEnoughForSupportMainPosition($hedge)) {self::echo(sprintf('%s size enough for support mainPosition => skip', $stoppedSupportPosition->getCaption())); return;}
        self::echo(sprintf('%s: %s stop closed at %f', __CLASS__, $stoppedPosition->getCaption(), $stopPrice));

        [$applyIfOppositePositionPnlGreaterThan, $supplyStopPnlDistancePct] = self::CONFIG[$symbol->value] ?? self::CONFIG['other'];
        $applyIfOppositePositionPnlGreaterThan = $this->applyIfMainPositionPnlGreaterThan ?? $applyIfOppositePositionPnlGreaterThan;

        $oppositePositionPnlPercent = $ticker->lastPrice->getPnlPercentFor($oppositePosition);
        if ($oppositePositionPnlPercent < $applyIfOppositePositionPnlGreaterThan) {
            self::echo(sprintf('%s: oppositePosition PNL is not enough for add supply stop => skip', __CLASS__)); return;
        }

        // @todo | disabled for now
        //       | need to make decision about create order or not
        //       | most probably on UTA account there will be available funds to buy opposite
        //       | choice must be based on some order context or global setting
//        $contractBalance = $this->exchangeAccountService->getContractWalletBalance($symbol->associatedCoin()); $orderCost = $this->orderCostCalculator->totalBuyCost(new ExchangeOrder($symbol, $closedVolume, $ticker->lastPrice), $stoppedSupportPosition->leverage, $stoppedSupportPosition->side)->value();
//        if ($contractBalance->available() > $orderCost) {self::echo(sprintf('FixMainHedgePositionListener: CONTRACT.availableBalance > %f (orderCost) => skip', $orderCost)); return;}

        $context = [
            Stop::CLOSE_BY_MARKET_CONTEXT => true,
            Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT => true,
            Stop::CREATED_AFTER_FIX_HEDGE_OPPOSITE_POSITION => true,
        ];

        $distance = FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta($supplyStopPnlDistancePct, $ticker->indexPrice), 0.1);
        $supplyStopPrice = $symbol->makePrice($stoppedPosition->isLong() ? $stopPrice + $distance : $stopPrice - $distance)->value();

        $loss = abs($stop->getPnlUsd($stoppedPosition));
        $oppositePositionStopVolume = PnlHelper::getVolumeForGetWishedProfit($loss, $oppositePosition->entryPrice()->deltaWith($supplyStopPrice));
        if ($stoppedPosition->isMainPosition()) {
            // otherwise fixed support volume occasionally might be not enough to cover all losses of main position
            $stoppedPart = Percent::fromPart($closedVolume / $stoppedPosition->size);
            $maximalVolumeOfOppositePositionToClose = $stoppedPart->of($oppositePosition->size);

            if ($oppositePositionStopVolume > $maximalVolumeOfOppositePositionToClose) {
                $oppositePositionStopVolume = $maximalVolumeOfOppositePositionToClose;
            }
//            $oppositePositionStopVolume = $stoppedPosition->getHedge()->getSupportRate()->of($oppositePositionStopVolume);
        }

        $oppositePositionStopVolume = $symbol->roundVolume($oppositePositionStopVolume);

        $this->stopService->create(
            symbol: $stoppedPosition->symbol,
            positionSide: $oppositePosition->side,
            price: $supplyStopPrice,
            volume: $oppositePositionStopVolume,
            context: $context
        );

        self::echo(sprintf('%s: supply stop created fot %s', __CLASS__, $stoppedPosition->getCaption()));
    }

    public static function echo(string $message): void
    {
        if (AppContext::isTest()) {
            return;
        }

        echo $message;
    }
}
