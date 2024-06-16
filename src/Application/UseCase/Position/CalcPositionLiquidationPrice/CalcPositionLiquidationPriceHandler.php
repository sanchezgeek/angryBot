<?php

declare(strict_types=1);

namespace App\Application\UseCase\Position\CalcPositionLiquidationPrice;

use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Domain\Position;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Price\Price;
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use LogicException;

/**
 * @todo It seems that the situation where `$contractWalletBalance->totalBalance < summaryPositionsIM` is not taken into account here (but must)
 */
final class CalcPositionLiquidationPriceHandler
{
    public function handle(Position $position, WalletBalance $contractWalletBalance): CalcPositionLiquidationPriceResult
    {
        if ($contractWalletBalance->accountType !== AccountType::CONTRACT) {
            throw new LogicException('Incorrect action run (expected CONTRACT balance provided)');
        }

        $estimatedBalance = $contractWalletBalance->availableBalance;
        if ($hedge = $position->getHedge()) {
            if (!$position->isMainPosition()) {
                throw new LogicException('Incorrect use: support position cannot be under liquidation?');
            }

            if ($position->getHedge()->isProfitableHedge()) {
                $expectedSupportProfit = $hedge->supportPosition->size * $position->getHedge()->getPositionsDistance();
                $estimatedBalance += $expectedSupportProfit;
            }
        }
        $freeBalanceLiquidationDistance = $estimatedBalance / $position->getSizeForCalcLoss();

        $maintenanceMargin = Percent::string('50%')->of($position->initialMargin);
        $maintenanceMarginLiquidationDistance = $maintenanceMargin->value() / $position->size;

        $liquidationDelta = $freeBalanceLiquidationDistance + $maintenanceMarginLiquidationDistance;

        $estimatedLiquidationPrice = Price::float($position->entryPrice)->modifyByDirection($position->side, PriceMovementDirection::TO_LOSS, $liquidationDelta);

        return new CalcPositionLiquidationPriceResult($estimatedLiquidationPrice);
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

        return new CalcPositionLiquidationPriceResult(Price::float($liquidationPrice));
    }
}
