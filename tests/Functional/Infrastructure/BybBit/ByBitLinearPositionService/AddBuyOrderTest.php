<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\ByBitLinearPositionService;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Tests\Factory\TickerFactory;
use App\Tests\Functional\Infrastructure\BybBit\ByBitLinearPositionServiceTestAbstract;
use App\Tests\Mock\Response\ByBit\TradeResponseBuilder;
use Symfony\Component\HttpClient\Response\MockResponse;

use function sprintf;
use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\ByBitLinearPositionService
 */
final class AddBuyOrderTest extends ByBitLinearPositionServiceTestAbstract
{
    /**
     * @dataProvider addBuyOrderTestCases
     */
    public function testBuyOrder(
        Symbol $symbol,
        AssetCategory $category,
        Side $positionSide,
        MockResponse $apiResponse,
        ?string $expectedExchangeOrderId
    ): void {
        // Arrange
        $position = new Position($positionSide, $symbol, 30000, 1.1, 33000, 31000, 330, 100);
        $ticker = TickerFactory::create($symbol, 29050);

        $this->matchPost(PlaceOrderRequest::buyOrderImmediatelyTriggeredByIndexPrice(
            $category,
            $symbol,
            $positionSide,
            $volume = 0.1,
            $price = 30000
        ), $apiResponse);

        // Act
        $exchangeOrderId = $this->service->addBuyOrder($position, $ticker, $price, $volume);

        // Assert
        self::assertEquals($expectedExchangeOrderId, $exchangeOrderId);
    }

    private function addBuyOrderTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $category = AssetCategory::linear;
        $positionSide = Side::Sell;

        yield sprintf('place %s %s position buy order (%s)', $symbol->value, $positionSide->title(), $category->value) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => TradeResponseBuilder::ok($exchangeOrderId = uuid_create())->build(),
            '$expectedExchangeOrderId' => $exchangeOrderId,
        ];
    }
}
