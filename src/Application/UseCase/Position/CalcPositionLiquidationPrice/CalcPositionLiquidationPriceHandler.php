<?php

declare(strict_types=1);

namespace App\Application\UseCase\Position\CalcPositionLiquidationPrice;

use App\Domain\Percent\FloatValue;
use App\Domain\Percent\ValueObject\Percent;
use App\Domain\Price\Price;

final class CalcPositionLiquidationPriceHandler
{
    public function handle(CalcPositionLiquidationPriceEntryDto $entryDto): CalcPositionLiquidationPriceResult
    {
        $position = $entryDto->getPosition();
        $contractBalance = $entryDto->getContractBalance();
        $currentFreeBalance = $contractBalance->sub(new FloatValue($position->initialMargin));
        $freeBalanceLiquidationDistance = $currentFreeBalance->value() / $position->size;

        // $maintenanceMarginLiquidationDistance = $maintenanceMarginRate->of($position->entryPrice / $position->positionLeverage);
        $maintenanceMargin = $position->initialMargin / 2;
        $maintenanceMarginLiquidationDistance = $maintenanceMargin / $position->size;

        $resLiquidationDelta = $maintenanceMarginLiquidationDistance + $freeBalanceLiquidationDistance;

        $liquidationPrice = $resLiquidationDelta + $position->entryPrice;

        return $this->result($liquidationPrice);
    }

    /**
     * @see https://www.bybit.com/en-US/help-center/article/Liquidation-Price-USDT-Contract
     */
    public function handleFromDocs(CalcPositionLiquidationPriceEntryDto $entryDto): CalcPositionLiquidationPriceResult
    {
        $position = $entryDto->getPosition();
        $contractBalance = $entryDto->getContractBalance();
        $currentFreeBalance = $contractBalance->sub(new FloatValue($position->initialMargin));
        $freeBalanceLiquidationDistance = $currentFreeBalance->value() / $position->size;

        $maintenanceMarginRate = Percent::fromString('0.5%');
        $initialMarginRate = 1 / $position->positionLeverage;

        $liquidationPrice = $position->isShort()
            ? $position->entryPrice * (1 + $initialMarginRate - $maintenanceMarginRate->part()) + $freeBalanceLiquidationDistance
            : $position->entryPrice * (1 - $initialMarginRate + $maintenanceMarginRate->part()) - $freeBalanceLiquidationDistance
        ;

        return $this->result($liquidationPrice);
    }

    private function result(float $liquidationPrice): CalcPositionLiquidationPriceResult
    {
        return new CalcPositionLiquidationPriceResult(Price::float($liquidationPrice));
    }
}
