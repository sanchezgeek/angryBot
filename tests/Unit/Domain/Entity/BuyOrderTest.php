<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Entity;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Common\HasExchangeOrderContext;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use PHPUnit\Framework\TestCase;

use function uuid_create;

/**
 * @covers \App\Bot\Domain\Entity\BuyOrder
 */
final class BuyOrderTest extends TestCase
{
    use PositionSideAwareTest;

    /** @see HasExchangeOrderContext::EXCHANGE_ORDER_ID_CONTEXT */
    private const EXCHANGE_ORDER_ID_CONTEXT = 'exchange.orderId';

    private const ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT = BuyOrder::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT;

    /**
     * @dataProvider positionSideProvider
     */
    public function testCanGetContextIfContextIsEmpty(Side $side): void
    {
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, []);

        self::assertSame([], $buyOrder->getContext());
    }

    /**
     * @dataProvider getContextTestDataProvider
     */
    public function testCanGetContext(Side $side, array $context): void
    {
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, $context);

        self::assertSame($context, $buyOrder->getContext());
    }

    /**
     * @dataProvider getContextTestDataProvider
     */
    public function testCanGetContextByName(Side $side, array $context, string $name, mixed $expectedValue): void
    {
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, $context);

        self::assertSame($expectedValue, $buyOrder->getContext($name));
        self::assertSame($expectedValue, $buyOrder->getContext()[$name]);
    }

    private function getContextTestDataProvider(): iterable
    {
        $name = 'some-context.value';

        foreach ($this->positionSides() as $side) {
            yield [$side, [$name => null], $name, null];
            yield [$side, [$name => true], $name, true];
            yield [$side, [$name => false], $name, false];

            yield [$side, [$name => 100500], $name, 100500];
            yield [$side, [$name => '100500'], $name, '100500'];

            yield [$side, [$name => 100.500], $name, 100.500];
            yield [$side, [$name => ['some-array' => 'context']], $name, ['some-array' => 'context']];
        }
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testExchangeOrderIdContext(Side $side): void
    {
        # after create with empty context
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side);
        self::assertFalse($buyOrder->hasExchangeOrderId());
        self::assertNull($buyOrder->getExchangeOrderId());

        # after create with context
        $exchangeOrderId = uuid_create();
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [self::EXCHANGE_ORDER_ID_CONTEXT => $exchangeOrderId]);
        self::assertTrue($buyOrder->hasExchangeOrderId());
        self::assertSame($exchangeOrderId, $buyOrder->getExchangeOrderId());

        # after set by method
        $exchangeOrderId = uuid_create();
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side);
        $buyOrder->setExchangeOrderId($exchangeOrderId);
        self::assertTrue($buyOrder->hasExchangeOrderId());
        self::assertSame($exchangeOrderId, $buyOrder->getExchangeOrderId());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testClearExchangeOrderIdContext(Side $side): void
    {
        $exchangeOrderId = uuid_create();
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [self::EXCHANGE_ORDER_ID_CONTEXT => $exchangeOrderId]);

        $buyOrder->clearExchangeOrderId();

        self::assertFalse($buyOrder->hasExchangeOrderId());
        self::assertNull($buyOrder->getExchangeOrderId());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testOnlyAfterExchangeOrderExecutedContext(Side $side): void
    {
        # after create with empty context
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, []);
        self::assertNull($buyOrder->getContext(self::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT));
        self::assertNull($buyOrder->getOnlyAfterExchangeOrderExecutedContext());

        # after create with context
        $exchangeOrderId = uuid_create();
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [self::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT => $exchangeOrderId]);
        self::assertSame($exchangeOrderId, $buyOrder->getContext(self::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT));
        self::assertSame($exchangeOrderId, $buyOrder->getOnlyAfterExchangeOrderExecutedContext());

        # after set by method
        $exchangeOrderId = uuid_create();
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side);
        $buyOrder->setOnlyAfterExchangeOrderExecutedContext($exchangeOrderId);
        self::assertSame([self::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT => $exchangeOrderId], $buyOrder->getContext());
        self::assertSame($exchangeOrderId, $buyOrder->getContext(self::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT));
        self::assertSame($exchangeOrderId, $buyOrder->getOnlyAfterExchangeOrderExecutedContext());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testIsWithShortStop(Side $side): void
    {
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side);
        self::assertFalse($buyOrder->isWithShortStop());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [BuyOrder::WITH_SHORT_STOP_CONTEXT => 100500]);
        self::assertFalse($buyOrder->isWithShortStop());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [BuyOrder::WITH_SHORT_STOP_CONTEXT => false]);
        self::assertFalse($buyOrder->isWithShortStop());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [BuyOrder::WITH_SHORT_STOP_CONTEXT => true]);
        self::assertTrue($buyOrder->isWithShortStop());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testIsForceBuyOrder(Side $side): void
    {
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side);
        self::assertFalse($buyOrder->isForceBuyOrder());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [BuyOrder::FORCE_BUY_CONTEXT => 100500]);
        self::assertFalse($buyOrder->isForceBuyOrder());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [BuyOrder::FORCE_BUY_CONTEXT => false]);
        self::assertFalse($buyOrder->isForceBuyOrder());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [BuyOrder::FORCE_BUY_CONTEXT => true]);
        self::assertTrue($buyOrder->isForceBuyOrder());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side);
        $buyOrder->setIsForceBuyOrderContext();
        self::assertTrue($buyOrder->isForceBuyOrder());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testIsOnlyIfHasAvailableBalanceContext(Side $side): void
    {
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side);
        self::assertFalse($buyOrder->isOnlyIfHasAvailableBalanceContextSet());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [BuyOrder::ONLY_IF_HAS_BALANCE_AVAILABLE_CONTEXT => 100500]);
        self::assertFalse($buyOrder->isOnlyIfHasAvailableBalanceContextSet());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [BuyOrder::ONLY_IF_HAS_BALANCE_AVAILABLE_CONTEXT => false]);
        self::assertFalse($buyOrder->isOnlyIfHasAvailableBalanceContextSet());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [BuyOrder::ONLY_IF_HAS_BALANCE_AVAILABLE_CONTEXT => true]);
        self::assertTrue($buyOrder->isOnlyIfHasAvailableBalanceContextSet());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testGetStopDistance(Side $side): void
    {
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side);
        self::assertNull($buyOrder->getStopDistance());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [BuyOrder::STOP_DISTANCE_CONTEXT => 100500]);
        self::assertSame(100500.0, $buyOrder->getStopDistance());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side);
        $buyOrder->setStopDistanceContext(123.1);
        self::assertSame(123.1, $buyOrder->getStopDistance());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testisOppositeBuyOrderAfterStopLoss(Side $side): void
    {
        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side);
        self::assertFalse($buyOrder->isOppositeBuyOrderAfterStopLoss());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [BuyOrder::IS_OPPOSITE_AFTER_SL_CONTEXT => 100500]);
        self::assertFalse($buyOrder->isOppositeBuyOrderAfterStopLoss());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [BuyOrder::IS_OPPOSITE_AFTER_SL_CONTEXT => false]);
        self::assertFalse($buyOrder->isOppositeBuyOrderAfterStopLoss());

        $buyOrder = new BuyOrder(1, 100500, 123.456, Symbol::ADAUSDT, $side, [BuyOrder::IS_OPPOSITE_AFTER_SL_CONTEXT => true]);
        self::assertTrue($buyOrder->isOppositeBuyOrderAfterStopLoss());
    }

    public function testDummy(): void
    {
        self::markTestIncomplete();
    }
}
