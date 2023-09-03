<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Entity\BuyOrder;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Common\HasExchangeOrderContext;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Mixin\PositionOrderTest;
use PHPUnit\Framework\TestCase;

use function uuid_create;

/**
 * @covers BuyOrder
 */
final class BuyOrderTest extends TestCase
{
    use PositionOrderTest;

    /** @see HasExchangeOrderContext::EXCHANGE_ORDER_ID_CONTEXT */
    private const EXCHANGE_ORDER_ID_CONTEXT = 'exchange.orderId';

    private const ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT = BuyOrder::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT;

    /**
     * @dataProvider positionSideProvider
     */
    public function testEmptyExchangeOrderIdContext(Side $side): void
    {
        $buyOrder = new BuyOrder(1, 100500, 123.456, 10, $side);

        self::assertFalse($buyOrder->hasExchangeOrderId());
        self::assertNull($buyOrder->getExchangeOrderId());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testGetExchangeOrderIdContext(Side $side): void
    {
        $exchangeOrderId = uuid_create();

        $buyOrder = new BuyOrder(1, 100500, 123.456, 10, $side, [self::EXCHANGE_ORDER_ID_CONTEXT => $exchangeOrderId]);

        self::assertTrue($buyOrder->hasExchangeOrderId());
        self::assertSame($exchangeOrderId, $buyOrder->getExchangeOrderId());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testSetExchangeOrderIdContext(Side $side): void
    {
        $exchangeOrderId = uuid_create();

        $buyOrder = new BuyOrder(1, 100500, 123.456, 10, $side);

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

        $buyOrder = new BuyOrder(1, 100500, 123.456, 10, $side, [self::EXCHANGE_ORDER_ID_CONTEXT => $exchangeOrderId]);

        $buyOrder->clearExchangeOrderId();

        self::assertFalse($buyOrder->hasExchangeOrderId());
        self::assertNull($buyOrder->getExchangeOrderId());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testEmptyOnlyAfterExchangeOrderExecutedContext(Side $side): void
    {
        $buyOrder = new BuyOrder(1, 100500, 123.456, 10, $side);

        self::assertNull($buyOrder->getContext(self::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT));
        self::assertArrayNotHasKey(self::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT, $buyOrder->getContext());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testCanGetOnlyAfterExchangeOrderExecutedContext(Side $side): void
    {
        $exchangeOrderId = uuid_create();

        $buyOrder = new BuyOrder(1, 100500, 123.456, 10, $side, [self::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT => $exchangeOrderId]);

        self::assertSame($exchangeOrderId, $buyOrder->getContext(self::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT));
        self::assertSame($exchangeOrderId, $buyOrder->getContext()[self::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT]);
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testCanSetOnlyAfterExchangeOrderExecutedContext(Side $side): void
    {
        $exchangeOrderId = uuid_create();

        $buyOrder = new BuyOrder(1, 100500, 123.456, 10, $side);

        $buyOrder->setOnlyAfterExchangeOrderExecutedContext($exchangeOrderId);

        self::assertSame($exchangeOrderId, $buyOrder->getContext(self::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT));
        self::assertSame($exchangeOrderId, $buyOrder->getContext()[self::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT]);
    }

    public function testDummy(): void
    {
        self::markTestIncomplete('isWithShortStop, ...');
    }
}
