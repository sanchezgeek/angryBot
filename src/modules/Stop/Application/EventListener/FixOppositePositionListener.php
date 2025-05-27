<?php

declare(strict_types=1);

namespace App\Stop\Application\EventListener;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Stop\Event\StopPushedToExchange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Stop\Application\Settings\FixOppositePositionSettings;
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
    public function __construct(
        private readonly AppSettingsProviderInterface $settings,
        private readonly OrderCostCalculator $orderCostCalculator,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionServiceInterface $positionService, /** @todo | MB without cache? */
        private readonly StopServiceInterface $stopService,
    ) {
    }

    /**
     * @todo publish to async
     */
    public function __invoke(StopPushedToExchange $event): void
    {
        $stop = $event->stop;
        $symbol = $stop->getSymbol();
        $positionSide = $stop->getPositionSide();

        if (!$this->settings->required(SettingAccessor::withAlternativesAllowed(FixOppositePositionSettings::FixOppositePosition_Enabled, $symbol, $positionSide))) {
            return;
        }

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

        $stopPrice = $stop->getPrice();
        $closedVolume = $stop->getVolume();

        $ticker = $this->exchangeService->ticker($symbol);
        if (!$stoppedPosition->isPositionInLoss($ticker->lastPrice)) {
            return;
        } // if ($this->hedgeService->isSupportSizeEnoughForSupportMainPosition($hedge)) {self::echo(sprintf('%s size enough for support mainPosition => skip', $stoppedSupportPosition->getCaption())); return;}
        self::echo(sprintf('%s: %s stop closed at %f', __CLASS__, $stoppedPosition->getCaption(), $stopPrice));

        /** @var Percent $applyIfOppositePositionPnlGreaterThan */
        $applyIfOppositePositionPnlGreaterThan = $this->settings->required(
            SettingAccessor::withAlternativesAllowed(FixOppositePositionSettings::FixOppositePosition_If_OppositePositionPnl_GreaterThan, $symbol, $positionSide)
        );

        if ($ticker->lastPrice->getPnlPercentFor($oppositePosition) < $applyIfOppositePositionPnlGreaterThan->value()) {
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

        /** @var Percent $supplyStopPnlDistancePct */
        $supplyStopPnlDistancePct = $this->settings->required(
            SettingAccessor::withAlternativesAllowed(FixOppositePositionSettings::FixOppositePosition_supplyStopPnlDistance, $symbol, $positionSide)
        );

        $distance = FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta($supplyStopPnlDistancePct, $ticker->indexPrice), 0.1);
        $supplyStopPrice = $symbol->makePrice($stoppedPosition->isLong() ? $stopPrice + $distance : $stopPrice - $distance)->value();

        $loss = abs($stop->getPnlUsd($stoppedPosition));
        $oppositePositionStopVolume = PnlHelper::getVolumeForGetWishedProfit($loss, $oppositePosition->entryPrice()->deltaWith($supplyStopPrice));
        if ($stoppedPosition->isMainPosition()) {
            // otherwise fixed support volume occasionally might be not enough to cover all losses of main position
            $stoppedPart = $closedVolume / $stoppedPosition->size;
            // @todo | take early stopped part into account
            $maximalVolumeOfOppositePositionToClose = $stoppedPart * $oppositePosition->size;

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
