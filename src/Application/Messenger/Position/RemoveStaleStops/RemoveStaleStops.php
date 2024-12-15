<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\RemoveStaleStops;

use App\Bot\Domain\ValueObject\Symbol;

/**
 * @codeCoverageIgnore
 */
final readonly class RemoveStaleStops
{
    public function __construct(public Symbol $symbol)
    {
    }
}
