<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\ByBitLinearPositionCacheDecoratedService;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\ByBitLinearPositionCacheDecoratedService;
use App\Tests\Factory\TickerFactory;

use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\ByBitLinearPositionCacheDecoratedService::addStop
 */
final class AddBuyOrderTest extends ByBitLinearPositionCacheDecoratedServiceTestAbstract
{
    public function testCallInnerServiceToAddStop(): void
    {
        // Arrange
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;
        $ticker = TickerFactory::create($symbol, 29050);
        $position = new Position($side, $symbol, 30000, 1.1, 33000, 31000, 330, 100);
        $volume = 0.1;
        $price = 30000;

        $this->innerService
            ->expects(self::once())->method('addBuyOrder')->with($position, $ticker, $price,$volume)
            ->willReturn($exchangeOrderId = uuid_create())
        ;

        // Act
        $result = $this->service->addBuyOrder($position, $ticker, $price, $volume);

        // Assert
        self::assertSame($exchangeOrderId, $result);
    }
}
