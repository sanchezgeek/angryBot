<?php

declare(strict_types=1);

namespace App\Trading\Contract\LockInProfit\Enum;

enum LockInProfitStrategy: string
{
    case BySteps = 'by_steps';
}
