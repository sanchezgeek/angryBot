<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\ByBitLinearPositionService;

use App\Bot\Application\Exception\ApiRateLimitReached;
use App\Bot\Application\Exception\MaxActiveCondOrdersQntReached;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Error;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Infrastructure\ByBit\ByBitLinearPositionService;
use App\Tests\Factory\TickerFactory;
use App\Tests\Functional\Infrastructure\BybBit\ByBitLinearPositionServiceTestAbstract;
use App\Tests\Mock\Response\ByBit\TradeResponseBuilder;
use RuntimeException;
use Symfony\Component\HttpClient\Response\MockResponse;

use Throwable;

use function sprintf;
use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\ByBitLinearPositionService
 */
final class AddStopTest extends ByBitLinearPositionServiceTestAbstract
{
    /**
     * @dataProvider addStopSuccessTestCases
     */
    public function testCanAddStop(
        Symbol $symbol,
        AssetCategory $category,
        Side $positionSide,
        MockResponse $apiResponse,
        ?string $expectedExchangeOrderId
    ): void {
        // Arrange
        $position = new Position($positionSide, $symbol, 30000, 1.1, 33000, 31000, 330, 100);
        $ticker = TickerFactory::create($symbol, 29050);

        $this->matchPost(PlaceOrderRequest::stopConditionalOrderTriggeredByIndexPrice(
            $category,
            $symbol,
            $positionSide,
            $volume = 0.1,
            $price = 30000
        ), $apiResponse);

        // Act
        $exchangeOrderId = $this->service->addStop($position, $ticker, $price, $volume);

        // Assert
        self::assertEquals($expectedExchangeOrderId, $exchangeOrderId);
    }

    /**
     * @dataProvider addStopFailTestCases
     */
    public function testFailAddStop(
        Symbol $symbol,
        AssetCategory $category,
        Side $positionSide,
        MockResponse $apiResponse,
        Throwable $expectedException
    ): void {
        // Arrange
        $position = new Position($positionSide, $symbol, 30000, 1.1, 33000, 31000, 330, 100);
        $ticker = TickerFactory::create($symbol, 29050);

        $this->matchPost(PlaceOrderRequest::stopConditionalOrderTriggeredByIndexPrice(
            $category,
            $symbol,
            $positionSide,
            $volume = 0.1,
            $price = 30000
        ), $apiResponse);

        $exception = null;
        try {
            $this->service->addStop($position, $ticker, $price, $volume);
        } catch (Throwable $exception) {
        }

        // Assert
        self::assertEquals($expectedException, $exception);
    }

    private function addStopSuccessTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $category = AssetCategory::linear;
        $positionSide = Side::Sell;

        yield sprintf('place %s %s position stop (%s)', $symbol->value, $positionSide->title(), $category->value) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => TradeResponseBuilder::ok($exchangeOrderId = uuid_create())->build(),
            '$expectedExchangeOrderId' => $exchangeOrderId,
        ];
    }

    private function addStopFailTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $category = AssetCategory::linear;
        $positionSide = Side::Sell;

        $error = ApiV5Error::ApiRateLimitReached;
        yield sprintf('API returned %d code (%s)', $error->code(), $error->desc()) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => TradeResponseBuilder::error($error)->build(),
            '$expectedException' => new ApiRateLimitReached(),
        ];

        $error = ApiV5Error::MaxActiveCondOrdersQntReached;
        yield sprintf('API returned %d code (%s)', $error->code(), $error->desc()) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => TradeResponseBuilder::error($error)->build(),
            '$expectedException' => new MaxActiveCondOrdersQntReached(),
        ];

        $error = ApiV5Error::CannotAffordOrderCost;
        yield sprintf('API returned %d code (%s)', $error->code(), $error->desc()) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => TradeResponseBuilder::error($error)->build(),
            '$expectedException' => new RuntimeException(
                sprintf('%s::%s | make `%s`: unknown err code (%d)', ByBitLinearPositionService::class, 'addStop', PlaceOrderRequest::URL, $error->code())
            ),
        ];
    }
}
