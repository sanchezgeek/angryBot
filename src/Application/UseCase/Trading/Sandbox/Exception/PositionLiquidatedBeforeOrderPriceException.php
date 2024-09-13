<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Exception;

use App\Application\UseCase\Trading\Sandbox\Dto\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\SandboxStopOrder;
use App\Bot\Domain\Position;

class PositionLiquidatedBeforeOrderPriceException extends \Exception
{
    public function __construct(public readonly SandboxBuyOrder|SandboxStopOrder $order, public readonly ?Position $position, string $message)
    {
        parent::__construct($message);
    }
}
