<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Mixin;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopChecksContext;

trait CheckBasedOnCurrentPositionState
{
    private readonly PositionServiceInterface $positionService;

    private function initPositionService(PositionServiceInterface $positionService): void
    {
        $this->positionService = $positionService;
    }

    public function enrichContextWithCurrentPositionState(Stop $stop, StopChecksContext $context): void
    {
        if (!$context->currentPositionState) {
            $context->currentPositionState = $this->positionService->getPosition($stop->getSymbol(), $stop->getPositionSide());
        }
    }
}
