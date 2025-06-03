<?php

declare(strict_types=1);

namespace data\todo\code;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \data\todo\code\VeryHardPriceAveragingCheck
 */
final class VeryHardPriceAveragingCheckTest extends TestCase
{
    /**
     * @dataProvider failedCheckTestCases
     */
    public function testFailedCheck(Position $position, Ticker $ticker): void
    {
        self::markTestSkipped();
        $order = new MarketBuyEntryDto($position->symbol, $position->side, $position->symbol->minOrderQty(), true);
        $check = new VeryHardPriceAveragingCheck($position);

        self::expectException(OrderExecutionLeadsToVeryHardPriceAveragingException::class);

        $check->check($order, $ticker);
    }

    public function failedCheckTestCases(): iterable
    {
        yield 'BTCUSDT LONG' => [
            PositionBuilder::long()->size(0.1)->entry(50000)->build(),
            TickerFactory::withEqualPrices(SymbolEnum::BTCUSDT, 51251)
        ];
    }

    /**
     * @dataProvider successCheckTestCases
     */
    public function testSuccessCheck(Position $position, Ticker $ticker): void
    {
        self::markTestSkipped();
        $order = new MarketBuyEntryDto($position->symbol, $position->side, $position->symbol->minOrderQty(), true);
        $check = new VeryHardPriceAveragingCheck($position);

        $check->check($order, $ticker);

        self::assertTrue(true);
    }

    public function successCheckTestCases(): iterable
    {
        yield 'BTCUSDT LONG' => [
            PositionBuilder::long()->size(0.1)->entry(50000)->build(),
            TickerFactory::withEqualPrices(SymbolEnum::BTCUSDT, 51250)
        ];
    }
}
