<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;

use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\V5\Enum\Order\TriggerBy;

use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService::activeConditionalOrders
 */
final class GetActiveConditionalOrdersTest extends ByBitLinearExchangeCacheDecoratedServiceTestAbstract
{
    public function testCallInnerService(): void
    {
        // Arrange
        $symbol = Symbol::BTCUSDT;
        $activeOrders = [
            new ActiveStopOrder($symbol, Side::Buy, uuid_create(), 0.01, 25000, TriggerBy::IndexPrice->value),
            new ActiveStopOrder($symbol, Side::Sell, uuid_create(), 0.1, 30000, TriggerBy::LastPrice->value),
        ];

        $this->innerService
            ->expects(self::once())->method('activeConditionalOrders')->with($symbol)
            ->willReturn($activeOrders)
        ;

        // Act
        $result = $this->service->activeConditionalOrders($symbol);

        // Assert
        self::assertSame($activeOrders, $result);
    }
}
