<?php

declare(strict_types=1);

namespace App\Trading\Contract\LockInProfit\Enum;

enum LockInProfitStrategy: string
{
    case StopsGrids = 'stops_by_steps';
    case Periodical_Fixations = 'periodical_fixations';
}
