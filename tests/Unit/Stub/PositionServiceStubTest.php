<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stub;

use App\Bot\Domain\ValueObject\Symbol;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Stub\Bot\PositionServiceStub;
use PHPUnit\Framework\TestCase;

use function array_replace;
use function uuid_create;

final class PositionServiceStubTest extends TestCase
{
    public function testAddStopMethodCalls(): void
    {
        // Arrange
        $position = PositionFactory::short(Symbol::BTCUSDT);
        $ticker = TickerFactory::create(Symbol::BTCUSDT);

        $mockedExchangeOrdersIds = [uuid_create(), uuid_create(), uuid_create()];

        $expectedMethodCalls = [
            [$position, $ticker, 29060.0, 0.1],
            [$position, $ticker, 29080.0, 0.2],
            [$position, $ticker, 29010.0, 0.3],
            [$position, $ticker, 29110.0, 0.4],
        ];

        $stub = new PositionServiceStub();
        $stub->setMockedExchangeOrdersIds($mockedExchangeOrdersIds);

        // Act
        $resultExchangeStopOrdersIds = [];
        $resultExchangeStopOrdersIds[] = $stub->addStop($position, $ticker, 29060, 0.1);
        $resultExchangeStopOrdersIds[] = $stub->addStop($position, $ticker, 29080, 0.2);
        $resultExchangeStopOrdersIds[] = $stub->addStop($position, $ticker, 29010, 0.3);
        $resultExchangeStopOrdersIds[] = $stub->addStop($position, $ticker, 29110, 0.4);

        # asser correct result method calls
        self::assertSame($expectedMethodCalls, $stub->getAddStopCallsStack());

        # asser addStop returns correct exchangeOrderId in result
        self::assertSame($resultExchangeStopOrdersIds, $stub->getPushedStopsExchangeOrderIds());

        # assert addStop use passed $mockedExchangeOrdersIds first
        self::assertSame(
            array_replace($stub->getPushedStopsExchangeOrderIds(), $mockedExchangeOrdersIds),
            $stub->getPushedStopsExchangeOrderIds()
        );
    }

    public function testDummyBuy(): void
    {
        self::markTestIncomplete('testAddBuyOrderMethodCalls, testCanReplaceSamePosition, testFailSetPositionWhenPositionAlreadyExists');
    }
}
