<?php

declare(strict_types=1);

namespace App\Buy\Application\Command;

final class CreateStopsAfterBuy
{
    public function __construct(public int $buyOrderId)
    {
    }
}
