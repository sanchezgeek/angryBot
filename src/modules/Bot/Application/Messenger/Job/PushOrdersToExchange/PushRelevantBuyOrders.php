<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

final readonly class PushRelevantBuyOrders
{
    public function __construct(public Symbol $symbol, public Side $side)
    {
    }
}
