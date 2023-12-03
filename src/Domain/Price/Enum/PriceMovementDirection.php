<?php

declare(strict_types=1);

namespace App\Domain\Price\Enum;

enum PriceMovementDirection
{
    case TO_LOSS;
    case TO_PROFIT;
    case NONE;

    public function isLoss(): bool
    {
        return $this === self::TO_LOSS;
    }

    public function isProfit(): bool
    {
        return $this === self::TO_PROFIT;
    }

    public function isNone(): bool
    {
        return $this === self::NONE;
    }
}
