<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitBySteps;

final class LockInProfitByStepsInnerDto
{
    public function __construct(
        public array $steps
    ) {
    }
}
