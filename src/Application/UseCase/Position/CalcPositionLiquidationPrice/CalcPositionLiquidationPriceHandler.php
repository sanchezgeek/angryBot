<?php

declare(strict_types=1);

namespace App\Application\UseCase\Position\CalcPositionLiquidationPrice;

use App\Bot\Domain\Position;
use App\Domain\Coin\CoinAmount;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\Price;
use App\Domain\Value\Percent\Percent;
use LogicException;

/**
 * @todo It seems that the situation where `$contractWalletBalance->totalBalance < summaryPositionsIM` is not taken into account here (but must)
 */
final class CalcPositionLiquidationPriceHandler
{
    public function handle(Position $position, CoinAmount $totalContractBalance): CalcPositionLiquidationPriceResult
    {
        if (($hedge = $position->getHedge()) && $hedge->isSupportPosition($position)) {
            throw new LogicException(__CLASS__ . ': incorrect usage (support position cannot be under liquidation)');
        }

        $freeContractBalance = self::getFreeContractBalance($totalContractBalance, $position);

        # freeContractBalance liquidation
        $balanceLiquidationDistance = $freeContractBalance->value() / $position->size;

        # supportPosition profit liquidation
        if ($hedge?->isProfitableHedge()) {
            // @todo | move calc to Position (information expert)?
            $expectedSupportProfit = $hedge->supportPosition->size * $position->getHedge()->getPositionsDistance();
            $balanceLiquidationDistance += $expectedSupportProfit / $position->getSizeForCalcLoss();
        }

        # maintenanceMargin liquidation
        $maintenanceMargin = Percent::string('50%')->of($position->initialMargin);
        $maintenanceMarginLiquidationDistance = $maintenanceMargin->value() / $position->getSizeForCalcLoss();

        $liquidationDistance = $balanceLiquidationDistance + $maintenanceMarginLiquidationDistance;

        $estimatedLiquidationPrice = Price::float($position->entryPrice)->modifyByDirection($position->side, PriceMovementDirection::TO_LOSS, $liquidationDistance);

        return new CalcPositionLiquidationPriceResult(Price::float($position->entryPrice), $estimatedLiquidationPrice);
    }

    /**
     * @see https://www.bybit.com/en-US/help-center/article/Liquidation-Price-USDT-Contract
     */
    public function handleFromDocs(Position $position, CoinAmount $totalContractBalance): CalcPositionLiquidationPriceResult
    {
        $freeBalanceLiquidationDistance = self::getFreeContractBalance($totalContractBalance, $position)->value() / $position->size;

        $maintenanceMarginRate = Percent::string('0.5%');
        $initialMarginRate = 1 / $position->leverage->value();

        $liquidationPrice = $position->isShort()
            ? $position->entryPrice * (1 + $initialMarginRate - $maintenanceMarginRate->part()) + $freeBalanceLiquidationDistance
            : $position->entryPrice * (1 - $initialMarginRate + $maintenanceMarginRate->part()) - $freeBalanceLiquidationDistance
        ;

        return new CalcPositionLiquidationPriceResult(Price::float($position->entryPrice), Price::float($liquidationPrice));
    }

    private static function getFreeContractBalance(CoinAmount $totalContractBalance, Position $position): CoinAmount
    {
        $sumPositionsIM = $position->initialMargin;

        if ($hedge = $position->getHedge()) {
            // @todo | recalculate support initial margin based on hedge data?
            $sumPositionsIM = $sumPositionsIM->add($hedge->supportPosition->initialMargin);
        }

        return $totalContractBalance->sub($sumPositionsIM);
    }
}
