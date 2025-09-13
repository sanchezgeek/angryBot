<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations;

use App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\Step\PeriodicalFixationStep;

final class LinpByPeriodicalFixationsStrategyDto
{
    /** @var PeriodicalFixationStep[] */
    public array $steps;

    public function __construct(
        PeriodicalFixationStep ...$steps,
    ) {
        $this->steps = $steps;
    }

    public function addStep(PeriodicalFixationStep $step): self
    {
        $this->steps[] = $step;

        return $this;
    }
}
