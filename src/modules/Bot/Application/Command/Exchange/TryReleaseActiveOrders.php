<?php

declare(strict_types=1);

namespace App\Bot\Application\Command\Exchange;

use App\Bot\Domain\ValueObject\Symbol;

readonly final class TryReleaseActiveOrders
{
    public function __construct(public Symbol $symbol)
    {
    }
}
