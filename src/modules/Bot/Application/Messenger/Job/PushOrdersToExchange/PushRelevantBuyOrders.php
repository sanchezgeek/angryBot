<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;

final readonly class PushRelevantBuyOrders
{
    public function __construct(public Symbol $symbol, public Side $side)
    {
    }
}
