<?php

declare(strict_types=1);

namespace App\Application\UseCase\Position\CalcPositionLiquidationPrice;

use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Domain\Position;
use App\Domain\Price\Price;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use LogicException;

final class CalcPositionLiquidationPriceHandler
{
    public function handle(Position $position, WalletBalance $contractWalletBalance): CalcPositionLiquidationPriceResult
    {
        if ($contractWalletBalance->accountType !== AccountType::CONTRACT) {
            throw new LogicException('Incorrect action run (expected CONTRACT balance provided)');
        }

        $positionSize = $position->size;

        $sizeInLoss = $position->size;
        $estimatedBalance = $contractWalletBalance->availableBalance;

        $oppositePosition = $position->oppositePosition;
        if ($oppositePosition !== null) {
            if (!$position->isMainPosition()) {
                throw new LogicException('Incorrect use: support position cannot be under liquidation?');
            }

            if ($position->getHedge()->isProfitableHedge()) {
                $expectedSupportProfit = $oppositePosition->size * $position->getHedge()->getPositionsDistance();
                $estimatedBalance += $expectedSupportProfit;
            }

            $sizeInLoss -= $oppositePosition->size;
        }

        $estimatedBalanceLiquidationDistance = $estimatedBalance / $sizeInLoss;

        $maintenanceMargin = Percent::string('50%')->of($position->initialMargin);
        $maintenanceMarginLiquidationDistance = $maintenanceMargin->value() / $positionSize;

        $resLiquidationDelta = $maintenanceMarginLiquidationDistance + $estimatedBalanceLiquidationDistance;

        $liquidationPrice = $resLiquidationDelta + $position->entryPrice;

        return $this->result($liquidationPrice);
    }

    /**
     * @see https://www.bybit.com/en-US/help-center/article/Liquidation-Price-USDT-Contract
     */
    public function handleFromDocs(Position $position, WalletBalance $contractWalletBalance): CalcPositionLiquidationPriceResult
    {
        $freeBalanceLiquidationDistance = $contractWalletBalance->availableBalance / $position->size;

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
        return new CalcPositionLiquidationPriceResult(
            Price::float(FloatHelper::round($liquidationPrice)),
        );
    }
}
