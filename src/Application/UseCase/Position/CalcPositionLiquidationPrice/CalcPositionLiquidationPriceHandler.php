<?php

declare(strict_types=1);

namespace App\Application\UseCase\Position\CalcPositionLiquidationPrice;

use App\Domain\Percent\ValueObject\Percent;
use App\Domain\Price\Price;

final class CalcPositionLiquidationPriceHandler
{
    public function handle(CalcPositionLiquidationPriceEntryDto $entryDto): CalcPositionLiquidationPriceResult
    {
        $position = $entryDto->getPosition();
        $positionSize = $position->size;

        $contractBalance = $entryDto->getContractBalance();
        $freeBalance = $contractBalance->sub($position->initialMargin);
        $freeBalanceLiquidationDistance = $freeBalance->value() / $positionSize;

        // $maintenanceMarginLiquidationDistance = $maintenanceMarginRate->of($position->entryPrice / $position->positionLeverage);
        $maintenanceMargin = Percent::string('50%')->of($position->initialMargin);
        $maintenanceMarginLiquidationDistance = $maintenanceMargin->value() / $positionSize;

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
        $freeBalance = $contractBalance->sub($position->initialMargin);
        $freeBalanceLiquidationDistance = $freeBalance->value() / $position->size;

        $maintenanceMarginRate = Percent::string('0.5%');
        $initialMarginRate = 1 / $position->leverage->value();

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
