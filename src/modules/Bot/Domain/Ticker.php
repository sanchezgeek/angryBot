<?php

declare(strict_types=1);

namespace App\Bot\Domain;

use App\Bot\Domain\ValueObject\Symbol;

final class Ticker
{
    public function __construct(
        public readonly Symbol $symbol,
        public readonly float  $markPrice,
        public readonly float  $indexPrice,
    ) {
    }
}
