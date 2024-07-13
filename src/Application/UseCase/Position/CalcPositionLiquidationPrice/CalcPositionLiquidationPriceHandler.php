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
final readonly class CalcPositionLiquidationPriceHandler
{
    public function handle(Position $position, CoinAmount $freeContractBalance): CalcPositionLiquidationPriceResult
    {
        self::checkPrerequisites($position);

        $fundsAvailableForLiquidation = $freeContractBalance->value();
        if (($hedge = $position->getHedge())?->isProfitableHedge()) {
            $fundsAvailableForLiquidation += $hedge->getSupportProfitOnMainEntryPrice();
        }
        $notCoveredSize = $position->getNotCoveredSize();
        $freeBalanceLiquidationDistance = $fundsAvailableForLiquidation / $notCoveredSize;
//        $notCoveredPartOrderDto = new ExchangeOrder($position->symbol, $notCoveredSize, $position->entryPrice); $closeFee = $this->orderCostCalculator->openFee($notCoveredPartOrderDto, $position->leverage, $position->side); $freeBalanceLiquidationDistance -= $closeFee->value();
        $liquidationDistance = $freeBalanceLiquidationDistance + $this->getMaintenanceMarginLiquidationDistance($position);

        if ($position->isLong() && $liquidationDistance >= $position->entryPrice) {
            return new CalcPositionLiquidationPriceResult(Price::float($position->entryPrice), Price::min());
        }

        $estimatedLiquidationPrice = Price::float($position->entryPrice)->modifyByDirection($position->side, PriceMovementDirection::TO_LOSS, $liquidationDistance);

        return new CalcPositionLiquidationPriceResult(Price::float($position->entryPrice), $estimatedLiquidationPrice);
    }

    /**
     * @see https://www.bybit.com/en-US/help-center/article/Liquidation-Price-USDT-Contract
     */
    public function handleFromDocs(Position $position, CoinAmount $freeContractBalance): CalcPositionLiquidationPriceResult
    {
        self::checkPrerequisites($position);

        $fundsAvailableForLiquidation = $freeContractBalance->value();
        if (($hedge = $position->getHedge())?->isProfitableHedge()) {
            $fundsAvailableForLiquidation += $hedge->getSupportProfitOnMainEntryPrice();
        }

        $freeBalanceLiquidationDistance = $fundsAvailableForLiquidation / $position->getNotCoveredSize();

        $maintenanceMarginRate = Percent::string('0.5%');
        $initialMarginRate = 1 / $position->leverage->value();

        $liquidationPrice = $position->isShort()
            ? $position->entryPrice * (1 + $initialMarginRate - $maintenanceMarginRate->part()) + $freeBalanceLiquidationDistance
            : $position->entryPrice * (1 - $initialMarginRate + $maintenanceMarginRate->part()) - $freeBalanceLiquidationDistance
        ;

        return new CalcPositionLiquidationPriceResult(Price::float($position->entryPrice), Price::float($liquidationPrice));
    }

    public function getMaintenanceMarginLiquidationDistance(Position $position): float
    {
        $maintenanceMargin = Percent::string('50%')->of($position->initialMargin);

        return $maintenanceMargin->value() / $position->size;
    }

    private static function checkPrerequisites(Position $position): void
    {
        if (($hedge = $position->getHedge()) && $hedge->isSupportPosition($position)) {
            throw new LogicException(__CLASS__ . ': incorrect usage (support position cannot be under liquidation)');
        }
    }
}
