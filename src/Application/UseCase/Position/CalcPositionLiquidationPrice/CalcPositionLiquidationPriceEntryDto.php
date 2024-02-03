<?php

declare(strict_types=1);

namespace App\Application\UseCase\Position\CalcPositionLiquidationPrice;

use App\Bot\Domain\Position;
use App\Domain\Coin\CoinAmount;

final class CalcPositionLiquidationPriceEntryDto
{
    private Position $position;
    private CoinAmount $contractBalance;

    public function __construct(
        Position $position,
        CoinAmount $contractBalance
    ) {
        $this->position = $position;
        $this->contractBalance = $contractBalance;
    }

    public function getPosition(): Position
    {
        return $this->position;
    }

    public function getContractBalance(): CoinAmount
    {
        return $this->contractBalance;
    }
}
