<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

/**
 * @codeCoverageIgnore
 */
final readonly class PushBuyOrders
{
    public function __construct(public SymbolInterface $symbol, public Side $side)
    {
    }
}
