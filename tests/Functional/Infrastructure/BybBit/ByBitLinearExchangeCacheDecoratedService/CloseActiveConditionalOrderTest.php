<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\ByBitLinearExchangeCacheDecoratedService;

use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\V5\Enum\Order\TriggerBy;

use function uuid_create;

final class CloseActiveConditionalOrderTest extends ByBitLinearExchangeCacheDecoratedServiceTestAbstract
{
    public function testCallInnerService(): void
    {
        // Arrange
        $symbol = Symbol::BTCUSDT;
        $order = new ActiveStopOrder($symbol, Side::Buy, uuid_create(), 0.01, 25000, TriggerBy::IndexPrice->value);

        // Assert
        $this->innerService->expects(self::once())->method('closeActiveConditionalOrder')->with($order);

        // Act
        $this->service->closeActiveConditionalOrder($order);
    }
}
