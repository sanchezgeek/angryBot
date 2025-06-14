<?php

declare(strict_types=1);

namespace App\Stop\Application\Contract\Command;

final class CreateOppositeStopsAfterBuy
{
    public function __construct(public int $buyOrderId)
    {
    }
}
