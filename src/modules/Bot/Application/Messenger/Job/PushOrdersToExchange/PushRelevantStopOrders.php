<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

final class PushRelevantStopOrders
{
    public function __construct(public readonly Symbol $symbol, public readonly Side $side)
    {
    }
}
