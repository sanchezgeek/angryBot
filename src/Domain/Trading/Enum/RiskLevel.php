<?php

declare(strict_types=1);

namespace App\Domain\Trading\Enum;

/**
 * @todo | settings | DRY with SafePriceAssertionStrategyEnum?
 * @todo new enum for trading style + applying when select timeframe and period e.g. for safeLiquidationPriceDelta
 * @todo нужна возможность вывести ВСË, что зависит от этого параметра (с возможностью его подмены для того, чтобы увидеть, как поменяются параметры)
 */
enum RiskLevel: string
{
    case Aggressive = 'aggressive';
    case Conservative = 'conservative';
    case Cautious = 'cautious';
}
