<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;

final class TickerFactory
{
    private const DEFAULT_PRICE = 29050;

    public static function create(Symbol $symbol, float $at = self::DEFAULT_PRICE): Ticker
    {
        return new Ticker($symbol, $at - 10, $at, 'test');
    }
}
