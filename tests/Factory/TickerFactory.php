<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Price;

final class TickerFactory
{
    private const DEFAULT_INDEX_PRICE = 29050;

    public static function create(Symbol $symbol, float $indexPrice = self::DEFAULT_INDEX_PRICE, ?float $markPrice = null): Ticker
    {
        $markPrice = $markPrice ?? $indexPrice - 10;
        return new Ticker($symbol, Price::float($markPrice), $indexPrice);
    }
}
