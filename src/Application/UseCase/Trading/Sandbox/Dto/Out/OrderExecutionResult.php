<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Dto\Out;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Domain\Position\ValueObject\Side;

class OrderExecutionResult
{
    public function __construct(
        public bool                             $success,
        public SandboxState                     $inputState,
        public SandboxStopOrder|SandboxBuyOrder $order,
        public SandboxState                     $outputState,
        public ?OrderExecutionFailResultReason  $failReason,
        public ?float                           $pnl
    ) {
        if (!$this->isOrderExecuted()) {
            assert($this->failReason !== null);
        } else {
            assert($this->failReason === null);
//            assert($this->pnl !== null);
        }
    }

    public function isOrderExecuted(): bool
    {
        return $this->success === true;
    }

    public function isPositionBeingClosed(Side $positionSide): bool
    {
        return $this->inputState->getPosition($positionSide) !== null && $this->outputState->getPosition($positionSide) === null;
    }

    public function isPositionBeenOpened(Side $positionSide): bool
    {
        return $this->inputState->getPosition($positionSide) === null && $this->outputState->getPosition($positionSide) !== null;
    }
}
