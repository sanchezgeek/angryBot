<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\ByBitLinearPositionCacheDecoratedService;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService;
use App\Tests\Factory\TickerFactory;

use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService::addConditionalStop
 */
final class AddStopTest extends ByBitLinearPositionCacheDecoratedServiceTestAbstract
{
    public function testCallInnerServiceToAddStop(): void
    {
        // Arrange
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;
        $position = new Position($side, $symbol, 30000, 1.1, 33000, 31000, 330, 330, 100);
        $volume = 0.1;
        $price = 30000;

        $triggerBy = TriggerBy::MarkPrice;

        $this->innerService
            ->expects(self::once())->method('addConditionalStop')->with($position, $price, $volume, $triggerBy)
            ->willReturn($exchangeOrderId = uuid_create())
        ;

        // Act
        $result = $this->service->addConditionalStop($position, $price, $volume, $triggerBy);

        // Assert
        self::assertSame($exchangeOrderId, $result);
    }
}
