<?php

declare(strict_types=1);

namespace App\Bot\Application\Message;

use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;

final class FindPositionStopsToAdd
{
    public function __construct(
        public readonly Symbol $symbol,
        public readonly Side $side
    ) {
    }
}
