<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiError;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Infrastructure\ByBit\Service\Exception\Trade\CannotAffordOrderCost;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mock\Response\ByBitV5Api\MarketResponseBuilder;
use App\Tests\Mock\Response\ByBitV5Api\TradeResponseBuilder;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

use function sprintf;
use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\Service\ByBitLinearPositionService::marketBuy
 */
final class AddBuyOrderTest extends ByBitLinearPositionServiceTestAbstract
{
    private const ORDER_QTY = 0.01;

    private const REQUEST_URL = PlaceOrderRequest::URL;
    private const CALLED_METHOD = 'ByBitLinearPositionService::marketBuy';

    /**
     * @dataProvider addBuyOrderSuccessTestCases
     */
    public function testCanAddBuyOrder(
        Symbol $symbol,
        AssetCategory $category,
        Side $positionSide,
        MockResponse $apiResponse,
        ?string $expectedExchangeOrderId
    ): void {
        // Arrange
        $position = new Position($positionSide, $symbol, 30000, 1.1, 33000, 31000, 330, 100);
        $ticker = TickerFactory::create($symbol, 29050);

        $this->matchPost(PlaceOrderRequest::marketBuy(
            $category,
            $symbol,
            $positionSide,
            $volume = self::ORDER_QTY,
            $price = 30000
        ), $apiResponse);

        // Act
        $exchangeOrderId = $this->service->marketBuy($position, $ticker, $price, $volume);

        // Assert
        self::assertEquals($expectedExchangeOrderId, $exchangeOrderId);
    }

    /**
     * @dataProvider addBuyOrderFailTestCases
     */
    public function testFailAddBuyOrder(
        Symbol $symbol,
        AssetCategory $category,
        Side $positionSide,
        MockResponse $apiResponse,
        Throwable $expectedException
    ): void {
        // Arrange
        $position = new Position($positionSide, $symbol, 30000, 1.1, 33000, 31000, 330, 100);
        $ticker = TickerFactory::create($symbol, 29050);

        $this->matchPost(PlaceOrderRequest::marketBuy(
            $category,
            $symbol,
            $positionSide,
            $volume = self::ORDER_QTY,
            $price = 30000
        ), $apiResponse);

        $exception = null;

        // Act
        try {
            $this->service->marketBuy($position, $ticker, $price, $volume);
        } catch (Throwable $exception) {
        }

        // Assert
        self::assertEquals($expectedException, $exception);
    }

    private function addBuyOrderSuccessTestCases(): iterable
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

    private function addBuyOrderFailTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $category = AssetCategory::linear;
        $positionSide = Side::Sell;

        $error = ByBitV5ApiError::knownError(ApiV5Errors::ApiRateLimitReached, $msg = 'Api rate limit reached');
        yield sprintf('API returned %d code (%s)', $error->code(), ApiV5Errors::ApiRateLimitReached->desc()) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => TradeResponseBuilder::error($error)->build(),
            '$expectedException' => new ApiRateLimitReached($msg),
        ];

        $error = ByBitV5ApiError::knownError(ApiV5Errors::CannotAffordOrderCost, 'Cannot afford');
        yield sprintf('API returned %d code (%s)', $error->code(), ApiV5Errors::CannotAffordOrderCost->desc()) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => TradeResponseBuilder::error($error)->build(),
            '$expectedException' => CannotAffordOrderCost::forBuy($symbol, $positionSide, self::ORDER_QTY),
        ];

        $error = ByBitV5ApiError::unknown(100500, 'Some other error');
        yield sprintf('API returned %d code (%s) => UnknownByBitApiErrorException', $error->code(), $error->msg()) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => TradeResponseBuilder::error($error)->build(),
            '$expectedException' => self::unknownV5ApiErrorException(self::REQUEST_URL, $error),
        ];

        $error = ByBitV5ApiError::knownError(ApiV5Errors::MaxActiveCondOrdersQntReached, ApiV5Errors::MaxActiveCondOrdersQntReached->desc());
        yield sprintf('API returned %d code (%s) => UnexpectedApiErrorException', $error->code(), $error->msg()) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => TradeResponseBuilder::error($error)->build(),
            '$expectedException' => self::unexpectedV5ApiErrorException(self::REQUEST_URL, $error, self::CALLED_METHOD),
        ];
    }
}
