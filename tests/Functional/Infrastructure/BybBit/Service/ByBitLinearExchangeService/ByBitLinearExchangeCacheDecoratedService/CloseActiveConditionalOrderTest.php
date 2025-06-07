<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService\ByBitLinearExchangeCacheDecoratedService;

use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;

use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService::closeActiveConditionalOrder
 */
final class CloseActiveConditionalOrderTest extends ByBitLinearExchangeCacheDecoratedServiceTestAbstract
{
    public function testCallInnerService(): void
    {
        // Arrange
        $symbol = SymbolEnum::BTCUSDT;
        $order = new ActiveStopOrder($symbol, Side::Buy, uuid_create(), 0.01, 25000, TriggerBy::IndexPrice->value);

        // Assert
        $this->innerService->expects(self::once())->method('closeActiveConditionalOrder')->with($order);

        // Act
        $this->service->closeActiveConditionalOrder($order);
    }
}
