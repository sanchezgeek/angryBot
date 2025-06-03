<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Order;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Order\ExchangeOrder;
use PHPUnit\Framework\TestCase;

final class ExchangeOrderTest extends TestCase
{
    public function testRoundToMin(): void
    {
        self::markTestIncomplete();
        $symbol = SymbolEnum::ATAUSDT;

        $currentPrice = $symbol->makePrice(0.21201);

        $volume = 10;
        $order = new ExchangeOrder($symbol, $volume, $currentPrice, true);
    }

    public function testRoundToFloatMin(): void
    {
        self::markTestIncomplete();
        $symbol = SymbolEnum::GNOUSDT;

        $currentPrice = $symbol->makePrice(284.92);

        $volume = 0.02;
        $order = new ExchangeOrder($symbol, $volume, $currentPrice, true);
//        var_dump($order->getVolume());die;
    }
}
