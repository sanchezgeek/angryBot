<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitByStopSteps;

use App\Trading\Application\LockInProfit\Strategy\LockInProfitByStopSteps\Step\LinpByStopsGridStep;

final class LinpByStopStepsStrategyDto
{
    /** @var LinpByStopsGridStep[] */
    public array $steps;

    public function __construct(
        LinpByStopsGridStep ...$steps
    ) {
        $this->steps = $steps;
    }

    public function addStep(LinpByStopsGridStep $step): self
    {
        $this->steps[] = $step;

        return $this;
    }
}
