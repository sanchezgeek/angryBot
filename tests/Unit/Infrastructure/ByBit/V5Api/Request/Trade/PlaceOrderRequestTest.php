<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Trade;

use App\Bot\Domain\ValueObject\Order\ExecutionOrderType;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Enum\Order\ConditionalOrderTriggerDirection;
use App\Infrastructure\ByBit\API\V5\Enum\Order\PositionIdx;
use App\Infrastructure\ByBit\API\V5\Enum\Order\TimeInForce;
use App\Infrastructure\ByBit\API\V5\Enum\Order\TriggerBy;
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
    public function testCreateImmediatelyTriggeredByIndexPriceOrderRequest(Side $side): void
    {
        $request = PlaceOrderRequest::marketOrder(
            $category = AssetCategory::linear,
            $symbol = Symbol::BTCUSDT,
            $side,
            0.01,
        );

        self::assertSame('/v5/order/create', $request->url());
        self::assertSame(Request::METHOD_POST, $request->method());
        self::assertTrue($request->isPrivateRequest());
        self::assertSame([
            'category' => $category->value,
            'symbol' => $symbol->value,
            'side' => ucfirst($side->value),
            'orderType' => ExecutionOrderType::Market->value,
            'triggerBy' => TriggerBy::IndexPrice->value,
            'timeInForce' => TimeInForce::GTC->value,
            'reduceOnly' => false,
            'closeOnTrigger' => false,
            'qty' => '0.01',
            'positionIdx' => $this->getPositionIdx($side)->value,
        ], $request->data());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testCreateStopConditionalOrderTriggeredByIndexPriceOrderRequest(Side $positionSide): void
    {
        $category = AssetCategory::linear;
        $symbol = Symbol::BTCUSDT;

        $request = PlaceOrderRequest::stopConditionalOrderTriggeredByIndexPrice(
            $category,
            $symbol,
            $positionSide,
            0.01,
            30000.1
        );

        self::assertSame('/v5/order/create', $request->url());
        self::assertSame(Request::METHOD_POST, $request->method());
        self::assertTrue($request->isPrivateRequest());
        self::assertSame([
            'category' => $category->value,
            'symbol' => $symbol->value,
            'side' => ucfirst($positionSide->getOpposite()->value),
            'orderType' => ExecutionOrderType::Market->value,
            'triggerBy' => TriggerBy::IndexPrice->value,
            'timeInForce' => TimeInForce::GTC->value,
            'reduceOnly' => true,
            'closeOnTrigger' => false,
            'qty' => '0.01',
            'positionIdx' => $this->getPositionIdx($positionSide)->value,
            'triggerPrice' => '30000.1',
            'triggerDirection' => $this->getConditionalStopTriggerDirection($positionSide)->value,
        ], $request->data());
    }

    private function getPositionIdx(Side $positionSide): PositionIdx
    {
        return $positionSide->isShort() ? PositionIdx::HedgeModeSellSide : PositionIdx::HedgeModeBuySide;
    }

    private function getConditionalStopTriggerDirection(Side $positionSide): ConditionalOrderTriggerDirection
    {
        return $positionSide->isShort() ? ConditionalOrderTriggerDirection::RisesToTriggerPrice : ConditionalOrderTriggerDirection::FallsToTriggerPrice;
    }
}
