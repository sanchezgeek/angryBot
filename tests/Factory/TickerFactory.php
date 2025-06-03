<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;

final class TickerFactory
{
    private const DEFAULT_INDEX_PRICE = 29050;

    public static function create(SymbolInterface $symbol, float $indexPrice = self::DEFAULT_INDEX_PRICE, ?float $markPrice = null, ?float $lastPrice = null): Ticker
    {
        $markPrice = $markPrice ?? $indexPrice - 10;
        $lastPrice = $lastPrice ?? $markPrice - 10;

        return new Ticker($symbol, $markPrice, $indexPrice, $lastPrice);
    }

    public static function withEqualPrices(SymbolInterface $symbol, float $price): Ticker
    {
        return new Ticker($symbol, $price, $price, $price);
    }

    public static function withMarkSomeBigger(SymbolInterface $symbol, float $price, Side $positionSide): Ticker
    {
        $modifier = $price / 3000;

        return $positionSide->isShort()
            ? new Ticker($symbol, $price, $price - $modifier, $price - $modifier)
            : new Ticker($symbol, $price, $price + $modifier, $price + $modifier)
        ;
    }
}
