<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Trade;

use App\Bot\Domain\ValueObject\Order\ExecutionOrderType;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\V5\Request\Trade\Enum\TimeInForceParam;
use App\Infrastructure\ByBit\API\V5\Request\Trade\Enum\TriggerByParam;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function ucfirst;

/**
 * @covers \App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest
 */
final class PlaceOrderRequestTest extends TestCase
{
    use PositionSideAwareTest;

    /**
     * @dataProvider positionSideProvider
     */
    public function testCreatePlaceBuyOrderImmediatelyTriggeredByIndexPriceRequest(Side $side): void
    {
        $request = new PlaceOrderRequest(
            $category = 'linear',
            $symbol = 'BTCUSDT',
            $side,
            $orderType = ExecutionOrderType::Market,
            $triggerBy = TriggerByParam::IndexPrice,
            $timeInForce = TimeInForceParam::GTC,
            false,
            false,
            0.01,
            30000.1
        );

        self::assertSame('/v5/order/create', $request->url());
        self::assertSame(Request::METHOD_POST, $request->method());
        self::assertSame([
            'category' => $category,
            'symbol' => $symbol,
            'side' => ucfirst($side->value),
            'orderType' => $orderType->value,
            'triggerBy' => $triggerBy->value,
            'timeInForce' => $timeInForce->value,
            'reduceOnly' => false,
            'closeOnTrigger' => false,
            'qty' => '0.01',
            'triggerPrice' => '30000.1',
        ], $request->data());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testCreatePlaceStopConditionalOrderTriggeredByIndexPrice(Side $side): void
    {
        $request = new PlaceOrderRequest(
            $category = 'linear',
            $symbol = 'BTCUSDT',
            $side,
            $orderType = ExecutionOrderType::Market,
            $triggerBy = TriggerByParam::IndexPrice,
            $timeInForce = TimeInForceParam::GTC,
            true,
            false,
            0.01,
            30000.1
        );

        self::assertSame('/v5/order/create', $request->url());
        self::assertSame(Request::METHOD_POST, $request->method());
        self::assertSame([
            'category' => $category,
            'symbol' => $symbol,
            'side' => ucfirst($side->value),
            'orderType' => $orderType->value,
            'triggerBy' => $triggerBy->value,
            'timeInForce' => $timeInForce->value,
            'reduceOnly' => true,
            'closeOnTrigger' => false,
            'qty' => '0.01',
            'triggerPrice' => '30000.1',
        ], $request->data());
    }
}
