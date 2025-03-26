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
final class FixMainHedgePositionListener
{
    public const ENABLED = true;

    const APPLY_IF_MAIN_POSITION_PNL_GREATER_THAN_DEFAULT = 300;

    /** @todo min of hedge distance and this value */
    const SUPPLY_STOP_PNL_DISTANCES = [
        Symbol::BTCUSDT->value => 40,
        Symbol::ETHUSDT->value => 80,
        'other' => 200
    ];

    public function __construct(
        private readonly OrderCostCalculator $orderCostCalculator,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionServiceInterface $positionService, /** @todo | MB without cache? */
        private readonly StopServiceInterface $stopService,
        private readonly float $applyIfMainPositionPnlGreaterThan = self::APPLY_IF_MAIN_POSITION_PNL_GREATER_THAN_DEFAULT,
    ) {
    }

    public function __invoke(StopPushedToExchange $event): void
    {
        if (!self::ENABLED) {
            return;
        }

        $stop = $event->stop;
        $symbol = $stop->getSymbol();
        $stopPrice = $stop->getPrice();
        $closedVolume = $stop->getVolume();

        if (!$stop->isCloseByMarketContextSet() || !$stop->isFixHedgeOnLossEnabled()) {
            return;
        }
//        if (!($closedVolume >= $symbol->minOrderQty())) return;
        $stoppedPosition = $this->positionService->getPosition($symbol, $stop->getPositionSide());
        if (!$oppositePosition = $stoppedPosition->oppositePosition) {
            return;
        }

        $ticker = $this->exchangeService->ticker($symbol);
        if (!$stoppedPosition->isPositionInLoss($ticker->lastPrice)) {
            return;
        }
//        if ($this->hedgeService->isSupportSizeEnoughForSupportMainPosition($hedge)) {
//            self::echo(sprintf('%s size enough for support mainPosition => skip', $stoppedSupportPosition->getCaption()));
//            return;
//        }
        self::echo(sprintf('FixMainHedgePositionListener: support stop closed at %f', $stopPrice));

        $oppositePositionPnlPercent = $ticker->lastPrice->getPnlPercentFor($oppositePosition);
        if ($oppositePositionPnlPercent < $this->applyIfMainPositionPnlGreaterThan) {
            self::echo('FixMainHedgePositionListener: mainPosition PNL is not enough for add supply stop => skip');
            return;
        }

        // @todo | disabled for now
        //       | need to make decision about create order or not
        //       | most probably on UTA account there will be available funds to buy opposite
        //       | choice must be based on some order context or global setting
//        $contractBalance = $this->exchangeAccountService->getContractWalletBalance($symbol->associatedCoin());
//        $orderCost = $this->orderCostCalculator->totalBuyCost(new ExchangeOrder($symbol, $closedVolume, $ticker->lastPrice), $stoppedSupportPosition->leverage, $stoppedSupportPosition->side)->value();
//
//        if ($contractBalance->available() > $orderCost) {
//            self::echo(sprintf('FixMainHedgePositionListener: CONTRACT.availableBalance > %f (orderCost) => skip', $orderCost));
//            return;
//        }

        $context = [Stop::CLOSE_BY_MARKET_CONTEXT => true, Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT => true];
        $percent = self::SUPPLY_STOP_PNL_DISTANCES[$symbol->value] ?? self::SUPPLY_STOP_PNL_DISTANCES['other'];
        $distance = FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta($percent, $ticker->indexPrice), 0.1);
        $supplyStopPrice = $symbol->makePrice(
            $stoppedPosition->isLong() ? $stopPrice + $distance : $stopPrice - $distance
        )->value();

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

        self::echo(sprintf('FixMainHedgePositionListener: supply stop created fot %s', $stoppedPosition->getCaption()));
    }

    public static function echo(string $message): void
    {
        if (AppContext::isTest()) {
            return;
        }

        echo $message;
    }
}
