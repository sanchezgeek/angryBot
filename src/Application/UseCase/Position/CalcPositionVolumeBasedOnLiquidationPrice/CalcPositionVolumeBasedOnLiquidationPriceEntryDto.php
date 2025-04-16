<?php

declare(strict_types=1);

namespace App\Application\UseCase\Position\CalcPositionVolumeBasedOnLiquidationPrice;

use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Domain\Position;
use App\Domain\Coin\CoinAmount;
use App\Domain\Price\Price;

final readonly class CalcPositionVolumeBasedOnLiquidationPriceEntryDto
{
    public function __construct(
        public Position $initialPositionState,
        public ContractBalance $contractBalance,
        public CoinAmount $freeContractBalanceForCalcLiquidation, // rid?
        public Price $wishedLiquidationPrice,
        public Price $currentPrice, # must be markPrice
    ) {
    }
}
