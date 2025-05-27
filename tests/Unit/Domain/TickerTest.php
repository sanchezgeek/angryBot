<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Tests\Factory\TickerFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Bot\Domain\Ticker
 */
class TickerTest extends TestCase
{
    public function testCreateTicker(): void
    {
        $symbol = Symbol::BTCUSDT;

        $ticker = TickerFactory::create($symbol, 200500.249, 100500.229, 90500.1);

        self::assertEquals($symbol, $ticker->symbol);
        self::assertEquals($symbol->makePrice(90500.1), $ticker->lastPrice);
        self::assertEquals($symbol->makePrice(100500.229), $ticker->markPrice);
        self::assertEquals($symbol->makePrice(200500.249), $ticker->indexPrice);

        self::assertEquals(90500.1, $ticker->lastPrice->value());
        self::assertEquals(100500.23, $ticker->markPrice->value());
        self::assertEquals(200500.25, $ticker->indexPrice->value());
    }

    /**
     * @dataProvider indexAlreadyOverStopTestCases
     */
    public function testIsIndexAlreadyOverStop(Ticker $ticker, Side $positionSide, float $stopPrice, bool $expectedResult): void
    {
        $result = $ticker->isIndexAlreadyOverStop($positionSide, $stopPrice);

        self::assertEquals($expectedResult, $result);
    }

    private function indexAlreadyOverStopTestCases(): iterable
    {
        yield 'over SHORT stop (-)' => [
            'ticker' => TickerFactory::create(Symbol::BTCUSDT, 200500, 100500),
            'stop.positionSide' => Side::Sell,
            'stop.price' => 200501,
            'expectedResult' => false,
        ];

        yield 'over SHORT stop (+)' => [
            'ticker' => TickerFactory::create(Symbol::BTCUSDT, 200500, 100500),
            'stop.positionSide' => Side::Sell,
            'stop.price' => 200500,
            'expectedResult' => true,
        ];

        yield 'over LONG stop (-)' => [
            'ticker' => TickerFactory::create(Symbol::BTCUSDT, 200500, 100500),
            'stop.positionSide' => Side::Buy,
            'stop.price' => 200499,
            'expectedResult' => false,
        ];

        yield 'over LONG stop (+)' => [
            'ticker' => TickerFactory::create(Symbol::BTCUSDT, 200500, 100500),
            'stop.positionSide' => Side::Buy,
            'stop.price' => 200500,
            'expectedResult' => true,
        ];
    }

    /**
     * @dataProvider indexAlreadyOverBuyOrderTestCases
     */
    public function testIsIndexAlreadyOverBuyOrder(Ticker $ticker, Side $positionSide, float $buyOrderPrice, bool $expectedResult): void
    {
        $result = $ticker->isIndexAlreadyOverBuyOrder($positionSide, $buyOrderPrice);

        self::assertEquals($expectedResult, $result);
    }

    private function indexAlreadyOverBuyOrderTestCases(): iterable
    {
        yield 'over SHORT buy order (+)' => [
            'ticker' => TickerFactory::create(Symbol::BTCUSDT, 200500, 100500),
            'stop.positionSide' => Side::Sell,
            'order.price' => 200500,
            'expectedResult' => true,
        ];

        yield 'over SHORT buy order (-)' => [
            'ticker' => TickerFactory::create(Symbol::BTCUSDT, 200500, 100500),
            'stop.positionSide' => Side::Sell,
            'order.price' => 200499,
            'expectedResult' => false,
        ];

        yield 'over LONG buy order (+)' => [
            'ticker' => TickerFactory::create(Symbol::BTCUSDT, 200500, 100500),
            'stop.positionSide' => Side::Buy,
            'order.price' => 200500,
            'expectedResult' => true,
        ];

        yield 'over LONG buy order (-)' => [
            'ticker' => TickerFactory::create(Symbol::BTCUSDT, 200500, 100500),
            'stop.positionSide' => Side::Buy,
            'order.price' => 200501,
            'expectedResult' => false,
        ];
    }

    /**
     * @dataProvider isLastPriceOverIndexPriceTestCases
     */
    public function testIsLastPriceOverIndexPrice(Ticker $ticker, Side $positionSide, bool $expectedResult): void
    {
        $result = $ticker->isLastPriceOverIndexPrice($positionSide);

        self::assertEquals($expectedResult, $result);
    }

    private function isLastPriceOverIndexPriceTestCases(): iterable
    {
        yield '[SHORT] last price over index price (+)' => [
            'ticker' => TickerFactory::create(Symbol::BTCUSDT, 30100, 30090, 30099),
            'positionSide' => Side::Sell,
            'expectedResult' => true,
        ];

        yield '[SHORT] last price over index price (-)' => [
            'ticker' => TickerFactory::create(Symbol::BTCUSDT, 30100, 30090, 300100),
            'positionSide' => Side::Sell,
            'expectedResult' => false,
        ];

        yield '[LONG] last price over index price (+)' => [
            'ticker' => TickerFactory::create(Symbol::BTCUSDT, 30100, 30090, 30101),
            'positionSide' => Side::Buy,
            'expectedResult' => true,
        ];

        yield '[LONG] last price over index price (-)' => [
            'ticker' => TickerFactory::create(Symbol::BTCUSDT, 30100, 30090, 30100),
            'positionSide' => Side::Buy,
            'expectedResult' => false,
        ];
    }
}
