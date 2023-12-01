<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stub;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
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
            [$position, 29060.0, 0.1, TriggerBy::IndexPrice],
            [$position, 29080.0, 0.2, TriggerBy::MarkPrice],
            [$position, 29010.0, 0.3, TriggerBy::LastPrice],
            [$position, 29110.0, 0.4, TriggerBy::IndexPrice],
        ];

        $stub = new PositionServiceStub();
        $stub->setMockedExchangeOrdersIds($mockedExchangeOrdersIds);

        // Act
        $resultExchangeStopOrdersIds = [];
        $resultExchangeStopOrdersIds[] = $stub->addConditionalStop($position, 29060, 0.1, TriggerBy::IndexPrice);
        $resultExchangeStopOrdersIds[] = $stub->addConditionalStop($position, 29080, 0.2, TriggerBy::MarkPrice);
        $resultExchangeStopOrdersIds[] = $stub->addConditionalStop($position, 29010, 0.3, TriggerBy::LastPrice);
        $resultExchangeStopOrdersIds[] = $stub->addConditionalStop($position, 29110, 0.4, TriggerBy::IndexPrice);

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
