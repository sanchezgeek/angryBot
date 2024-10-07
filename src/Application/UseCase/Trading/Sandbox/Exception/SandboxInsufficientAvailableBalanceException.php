<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Exception;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use Exception;

class SandboxInsufficientAvailableBalanceException extends AbstractSandboxExecutionFlowException
{
    public function __construct(public readonly SandboxBuyOrder $order, string $message)
    {
        parent::__construct($message);
    }

    public static function whenTryToBuy(SandboxBuyOrder $order, string $reason): self
    {
        return new self($order, $reason);
    }
}
