<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsInProfit;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

final class CheckPositionIsInProfitParams
{
    public const SUPPRESSED_FOR_SYMBOLS = [
//        Symbol::BTCUSDT,
//        [Symbol::BTCUSDT, Side::Buy],
    ];

    public const SYMBOLS_ALERT_PNL_PERCENT = [
//        Symbol::ETHUSDT->value => 500,
    ];
}
