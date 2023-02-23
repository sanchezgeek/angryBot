<?php

declare(strict_types=1);

namespace App\Bot\Application\Events\Stop;

use App\Bot\Domain\Entity\Stop;

final readonly class StopPushedToExchange
{
    public function __construct(public int $stopId)
    {
    }
}
