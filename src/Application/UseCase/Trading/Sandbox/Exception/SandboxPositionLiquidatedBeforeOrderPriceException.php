<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Exception;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Bot\Domain\Position;

class SandboxPositionLiquidatedBeforeOrderPriceException extends AbstractSandboxExecutionFlowException
{
    public function __construct(public readonly SandboxBuyOrder|SandboxStopOrder $order, public readonly ?Position $position, string $message)
    {
        parent::__construct($message);
    }
}
