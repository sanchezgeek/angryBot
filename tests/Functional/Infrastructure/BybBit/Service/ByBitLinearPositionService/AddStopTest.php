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
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\Trade\TickerOverConditionalOrderTriggerPrice;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mock\Response\ByBitV5Api\ErrorResponseFactory;
use App\Tests\Mock\Response\ByBitV5Api\PlaceOrderResponseBuilder;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

use function sprintf;
use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\Service\ByBitLinearPositionService::addConditionalStop
 */
final class AddStopTest extends ByBitLinearPositionServiceTestAbstract
{
    private const REQUEST_URL = PlaceOrderRequest::URL;
    private const CALLED_METHOD = 'ByBitLinearPositionService::addConditionalStop';

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
        $exchangeOrderId = $this->service->addConditionalStop($position, $ticker, $price, $volume);

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

        // Act
        try {
            $this->service->addConditionalStop($position, $ticker, $price, $volume);
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
            '$apiResponse' => PlaceOrderResponseBuilder::ok($exchangeOrderId = uuid_create())->build(),
            '$expectedExchangeOrderId' => $exchangeOrderId,
        ];
    }

    private function addStopFailTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $category = AssetCategory::linear;
        $positionSide = Side::Sell;

        $error = ByBitV5ApiError::knownError(ApiV5Errors::ApiRateLimitReached, $msg = 'Api rate limit reached');
        yield sprintf('API returned %d code (%s)', $error->code(), ApiV5Errors::ApiRateLimitReached->desc()) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => PlaceOrderResponseBuilder::error($error)->build(),
            '$expectedException' => new ApiRateLimitReached($msg),
        ];

        $error = ByBitV5ApiError::knownError(ApiV5Errors::MaxActiveCondOrdersQntReached, $msg = 'Max orders');
        yield sprintf('API returned %d code (%s)', $error->code(), $msg) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => PlaceOrderResponseBuilder::error($error)->build(),
            '$expectedException' => new MaxActiveCondOrdersQntReached($msg),
        ];

        $error = ByBitV5ApiError::knownError(ApiV5Errors::BadRequestParams, $msg = 'expect Rising, but trigger_price[346380000] <= current[346388800]??3');
        yield sprintf('API returned %d code (%s)', $error->code(), $msg) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => ErrorResponseFactory::error($error->code(), $msg),
            '$expectedException' => new TickerOverConditionalOrderTriggerPrice($msg),
        ];

        $error = ByBitV5ApiError::unknown(100500, 'Some other error');
        yield sprintf('API returned %d code (%s) => UnknownByBitApiErrorException', $error->code(), $error->msg()) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => PlaceOrderResponseBuilder::error($error)->build(),
            '$expectedException' => self::unknownV5ApiErrorException(self::REQUEST_URL, $error),
        ];

        $error = ByBitV5ApiError::knownError(ApiV5Errors::CannotAffordOrderCost, ApiV5Errors::CannotAffordOrderCost->desc());
        yield sprintf('API returned %d code (%s) => UnexpectedApiErrorException', $error->code(), $error->msg()) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => PlaceOrderResponseBuilder::error($error)->build(),
            '$expectedException' => self::unexpectedV5ApiErrorException(self::REQUEST_URL, $error, self::CALLED_METHOD),
        ];
    }
}
