<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;

/**
 * @codeCoverageIgnore
 */
final readonly class PushBuyOrders
{
    public function __construct(public SymbolInterface $symbol, public Side $side)
    {
    }
}
