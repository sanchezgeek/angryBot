<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Trade;

use App\Bot\Domain\ValueObject\Order\ExecutionOrderType;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Enum\Order\ConditionalOrderTriggerDirection;
use App\Infrastructure\ByBit\API\V5\Enum\Order\PositionIdx;
use App\Infrastructure\ByBit\API\V5\Enum\Order\TimeInForce;
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
    public function testPlaceMarketBuyOrderRequest(Side $side): void
    {
        // Arrange
        $category = AssetCategory::linear;
        $symbol = Symbol::BTCUSDT;

        // Act
        $request = PlaceOrderRequest::marketBuy($category, $symbol, $side, 0.01);

        // Assert
        self::assertSame('/v5/order/create', $request->url());
        self::assertSame(Request::METHOD_POST, $request->method());
        self::assertTrue($request->isPrivateRequest());
        self::assertSame([
            'category' => $category->value,
            'symbol' => $symbol->value,
            'side' => ucfirst($side->value),
            'orderType' => ExecutionOrderType::Market->value,
            'timeInForce' => TimeInForce::GTC->value,
            'reduceOnly' => false,
            'closeOnTrigger' => false,
            'qty' => '0.01',
            'positionIdx' => $this->getPositionIdx($side)->value,
            'triggerBy' => TriggerBy::IndexPrice->value, // @todo | apiV5 | remove + check
        ], $request->data());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testPlaceMarketCloseOrderRequest(Side $positionSide): void
    {
        // Arrange
        $category = AssetCategory::linear;
        $symbol = Symbol::BTCUSDT;

        $expectedOrderSide = $positionSide->getOpposite();

        // Act
        $request = PlaceOrderRequest::marketClose($category, $symbol, $positionSide, 0.01);

        // Assert
        self::assertSame('/v5/order/create', $request->url());
        self::assertSame(Request::METHOD_POST, $request->method());
        self::assertTrue($request->isPrivateRequest());
        self::assertSame([
            'category' => $category->value,
            'symbol' => $symbol->value,
            'side' => ucfirst($expectedOrderSide->value),
            'orderType' => ExecutionOrderType::Market->value,
            'timeInForce' => TimeInForce::GTC->value,
            'reduceOnly' => true,
            'closeOnTrigger' => false,
            'qty' => '0.01',
            'positionIdx' => $this->getPositionIdx($positionSide)->value,
            'triggerBy' => TriggerBy::IndexPrice->value, // @todo | apiV5 | remove + check
        ], $request->data());
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testPlaceLimitTpOrderRequest(Side $positionSide): void
    {
        // Arrange
        $category = AssetCategory::linear;
        $symbol = Symbol::BTCUSDT;

        $expectedOrderSide = $positionSide->getOpposite();
        $expectedTriggerDirection = $this->getLimitTPTriggerDirection($positionSide);

        // Act
        $request = PlaceOrderRequest::limitTP($category, $symbol, $positionSide, 0.01, 30000.1);

        // Assert
        self::assertSame('/v5/order/create', $request->url());
        self::assertSame(Request::METHOD_POST, $request->method());
        self::assertTrue($request->isPrivateRequest());
        self::assertSame([
            'category' => $category->value,
            'symbol' => $symbol->value,
            'side' => ucfirst($expectedOrderSide->value),
            'orderType' => ExecutionOrderType::Limit->value,
            'timeInForce' => TimeInForce::GTC->value,
            'reduceOnly' => true,
            'closeOnTrigger' => false,
            'qty' => '0.01',
            'positionIdx' => $this->getPositionIdx($positionSide)->value,
            'price' => '30000.1',
            'triggerDirection' => $expectedTriggerDirection->value,
        ], $request->data());
    }

    /**
     * @dataProvider createStopConditionalOrderRequestTestCases
     */
    public function testCreateStopConditionalOrderRequest(Side $positionSide, TriggerBy $triggerBy): void
    {
        // Arrange
        $category = AssetCategory::linear;
        $symbol = Symbol::BTCUSDT;

        $expectedOrderSide = $positionSide->getOpposite();
        $expectedTriggerDirection = $this->getConditionalStopTriggerDirection($positionSide);

        // Act
        $request = PlaceOrderRequest::stopConditionalOrder($category, $symbol, $positionSide, 0.01, 30000.1, $triggerBy);

        // Assert
        self::assertSame('/v5/order/create', $request->url());
        self::assertSame(Request::METHOD_POST, $request->method());
        self::assertTrue($request->isPrivateRequest());

        self::assertSame([
            'category' => $category->value,
            'symbol' => $symbol->value,
            'side' => ucfirst($expectedOrderSide->value),
            'orderType' => ExecutionOrderType::Market->value,
            'timeInForce' => TimeInForce::GTC->value,
            'reduceOnly' => true,
            'closeOnTrigger' => false,
            'qty' => '0.01',
            'positionIdx' => $this->getPositionIdx($positionSide)->value,
            'triggerBy' => $triggerBy->value,
            'triggerPrice' => '30000.1',
            'triggerDirection' => $expectedTriggerDirection->value,
        ], $request->data());
    }

    private function createStopConditionalOrderRequestTestCases(): iterable
    {
        foreach ($this->positionSides() as $positionSide) {
            yield ['side' => $positionSide, 'triggerBy' => TriggerBy::IndexPrice];
            yield ['side' => $positionSide, 'triggerBy' => TriggerBy::MarkPrice];
            yield ['side' => $positionSide, 'triggerBy' => TriggerBy::LastPrice];
        }
    }

    private function getPositionIdx(Side $positionSide): PositionIdx
    {
        return $positionSide->isShort() ? PositionIdx::HedgeModeSellSide : PositionIdx::HedgeModeBuySide;
    }

    private function getConditionalStopTriggerDirection(Side $positionSide): ConditionalOrderTriggerDirection
    {
        return $positionSide->isShort() ? ConditionalOrderTriggerDirection::RisesToTriggerPrice : ConditionalOrderTriggerDirection::FallsToTriggerPrice;
    }

    private function getLimitTPTriggerDirection(Side $positionSide): ConditionalOrderTriggerDirection
    {
        return $positionSide->isShort() ? ConditionalOrderTriggerDirection::FallsToTriggerPrice : ConditionalOrderTriggerDirection::RisesToTriggerPrice;
    }
}
