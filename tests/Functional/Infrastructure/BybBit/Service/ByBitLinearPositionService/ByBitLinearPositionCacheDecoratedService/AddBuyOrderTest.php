<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\ByBitLinearPositionCacheDecoratedService;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService;
use App\Tests\Factory\TickerFactory;

use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService::marketBuy
 */
final class AddBuyOrderTest extends ByBitLinearPositionCacheDecoratedServiceTestAbstract
{
    public function testCallInnerServiceToMakeBuy(): void
    {
        // Arrange
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;
        $ticker = TickerFactory::create($symbol, 29050);
        $position = new Position($side, $symbol, 30000, 1.1, 33000, 31000, 330, 100);
        $volume = 0.1;
        $price = 30000;

        $this->innerService
            ->expects(self::once())->method('marketBuy')->with($position, $ticker, $price, $volume)
            ->willReturn($exchangeOrderId = uuid_create())
        ;

        // Act
        $result = $this->service->marketBuy($position, $ticker, $price, $volume);

        // Assert
        self::assertSame($exchangeOrderId, $result);
    }
}
