<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiError;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\Trade\TickerOverConditionalOrderTriggerPrice;
use App\Tests\Factory\Position\PositionBuilder;
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
        SymbolInterface $symbol,
        AssetCategory $category,
        Side $positionSide,
        TriggerBy $triggerBy,
        MockResponse $apiResponse,
        ?string $expectedExchangeOrderId
    ): void {
        // Arrange
        $position = PositionBuilder::bySide($positionSide)->build();

        $this->matchPost(PlaceOrderRequest::stopConditionalOrder(
            $category,
            $symbol,
            $positionSide,
            $volume = 0.1,
            $price = 30000,
            $triggerBy
        ), $apiResponse);

        // Act
        $exchangeOrderId = $this->service->addConditionalStop($position, $price, $volume, $triggerBy);

        // Assert
        self::assertEquals($expectedExchangeOrderId, $exchangeOrderId);
    }

    private function addStopSuccessTestCases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $category = AssetCategory::linear;
        $positionSide = Side::Sell;

        foreach ([TriggerBy::IndexPrice, TriggerBy::MarkPrice, TriggerBy::LastPrice] as $triggerBy) {
            yield sprintf('[triggerBy=%s] place %s %s position stop (%s)', $triggerBy->value, $symbol->value, $positionSide->title(), $category->value) => [
                $symbol, $category, $positionSide, $triggerBy,
                '$apiResponse' => PlaceOrderResponseBuilder::ok($exchangeOrderId = uuid_create())->build(),
                '$expectedExchangeOrderId' => $exchangeOrderId,
            ];
        }
    }

    /**
     * @dataProvider addStopFailTestCases
     */
    public function testFailAddStop(
        SymbolInterface $symbol,
        AssetCategory $category,
        Side $positionSide,
        MockResponse $apiResponse,
        Throwable $expectedException
    ): void {
        // Arrange
        $position = PositionBuilder::bySide($positionSide)->build();

        $this->matchPost(PlaceOrderRequest::stopConditionalOrder(
            $category,
            $symbol,
            $positionSide,
            $volume = 0.1,
            $price = 30000,
            TriggerBy::IndexPrice
        ), $apiResponse);

        $exception = null;

        // Act
        try {
            $this->service->addConditionalStop($position, $price, $volume, TriggerBy::IndexPrice);
        } catch (Throwable $exception) {
        }

        // Assert
        self::assertEquals($expectedException, $exception);
    }

    private function addStopFailTestCases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
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

        $error = ByBitV5ApiError::knownError(ApiV5Errors::BadRequestParams2, $msg = 'expect Rising, but trigger_price[346380000] <= current[346388800]??3');
        yield sprintf('API returned %d code (%s)', $error->code(), $msg) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => ErrorResponseFactory::error($error->code(), $msg),
            '$expectedException' => new TickerOverConditionalOrderTriggerPrice($msg),
        ];

        $error = ByBitV5ApiError::knownError(ApiV5Errors::BadRequestParams3, $msg = 'expect Rising, but trigger_price[346380000] <= current[346388800]??3');
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

        $positionSide = Side::Buy;

        $error = ByBitV5ApiError::knownError(ApiV5Errors::BadRequestParams, $msg = 'expect Falling, but trigger_price[346380000] >= current[346388800]??3');
        yield sprintf('API returned %d code (%s)', $error->code(), $msg) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => ErrorResponseFactory::error($error->code(), $msg),
            '$expectedException' => new TickerOverConditionalOrderTriggerPrice($msg),
        ];

        $error = ByBitV5ApiError::knownError(ApiV5Errors::BadRequestParams2, $msg = 'expect Falling, but trigger_price[346380000] >= current[346388800]??3');
        yield sprintf('API returned %d code (%s)', $error->code(), $msg) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => ErrorResponseFactory::error($error->code(), $msg),
            '$expectedException' => new TickerOverConditionalOrderTriggerPrice($msg),
        ];

        $error = ByBitV5ApiError::knownError(ApiV5Errors::BadRequestParams3, $msg = 'expect Falling, but trigger_price[346380000] >= current[346388800]??3');
        yield sprintf('API returned %d code (%s)', $error->code(), $msg) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => ErrorResponseFactory::error($error->code(), $msg),
            '$expectedException' => new TickerOverConditionalOrderTriggerPrice($msg),
        ];
    }
}
