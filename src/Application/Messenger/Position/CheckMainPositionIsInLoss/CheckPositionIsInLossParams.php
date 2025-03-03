<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckMainPositionIsInLoss;

use App\Bot\Domain\ValueObject\Symbol;

final class CheckPositionIsInLossParams
{
    public const SUPPRESSED_FOR_SYMBOLS = [
//        Symbol::BTCUSDT,
//        Symbol::ETHUSDT,
    ];
}
