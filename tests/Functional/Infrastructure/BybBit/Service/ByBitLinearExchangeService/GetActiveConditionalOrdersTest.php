<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService;

use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Enum\Order\TriggerBy;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiError;
use App\Infrastructure\ByBit\API\V5\Request\Trade\GetCurrentOrdersRequest;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use App\Tests\Mock\Response\ByBitV5Api\Trade\CancelOrderResponseBuilder;
use App\Tests\Mock\Response\ByBitV5Api\Trade\CurrentOrdersResponseBuilder;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

use function sprintf;
use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\Service\ByBitLinearExchangeService::activeConditionalOrders
 */
final class GetActiveConditionalOrdersTest extends ByBitLinearExchangeServiceTestAbstract
{
    use PositionSideAwareTest;
    use ByBitV5ApiTester;

    private const REQUEST_URL = GetCurrentOrdersRequest::URL;
    private const CALLED_METHOD = 'ByBitLinearExchangeService::activeConditionalOrders';

    /**
     * @dataProvider getActiveConditionalOrdersTestSuccessCases
     */
    public function testCanGetActiveConditionalOrders(
        AssetCategory $category,
        Symbol $symbol,
        MockResponse $apiResponse,
        array $expectedActiveStopOrders
    ): void {
        // Arrange
        $orderId = uuid_create();
        $this->matchGet(GetCurrentOrdersRequest::openOnly($category, $symbol), $apiResponse);

        // Act
        $activeConditionalOrders = $this->service->activeConditionalOrders($symbol);

        // Assert
        self::assertEquals($expectedActiveStopOrders, $activeConditionalOrders);
    }

    private function getActiveConditionalOrdersTestSuccessCases(): iterable
    {
        $category = self::ASSET_CATEGORY;

        $symbol = Symbol::BTCUSDT;

        yield sprintf('get active %s stops (%s)', $symbol->value, $category->value) => [
            $category, $symbol,
            '$apiResponse' => CurrentOrdersResponseBuilder::ok($category)
                # active LONG conditional stops
                ->withOrder(
                    $symbol,
                    Side::Sell,
                    $firstLongStopId = uuid_create(),
                    $firstLongStopTriggerPrice = 30000,
                    $firstLongStopQty = 0.1,
                    $firstLongStopTriggerBy = TriggerBy::IndexPrice,
                    true,
                    false,
                )
                ->withOrder(
                    $symbol,
                    Side::Sell,
                    $secondLongStopId = uuid_create(),
                    $secondLongStopTriggerPrice = 32000,
                    $secondLongStopQty = 0.3,
                    $secondLongStopTriggerBy = TriggerBy::MarkPrice,
                    true,
                    false,
                )
                # active SHORT conditional stops
                ->withOrder(
                    $symbol,
                    Side::Buy,
                    $shortStopId = uuid_create(),
                    $shortStopTriggerPrice = 33000,
                    $shortStopQty = 0.4,
                    $shortStopTriggerBy = TriggerBy::LastPrice,
                    true,
                    false,
                )
                # not conditional stops
                ->withOrder(
                    $symbol,
                    Side::Sell,
                    uuid_create(),
                    31000,
                    0.01,
                    TriggerBy::IndexPrice,
                    false,
                    false,
                )->build(),
            '$expectedActiveOrders' => [
                # active LONG conditional stops
                new ActiveStopOrder(
                    $symbol,
                    Side::Buy,
                    $firstLongStopId,
                    $firstLongStopQty,
                    $firstLongStopTriggerPrice,
                    $firstLongStopTriggerBy->value
                ),
                new ActiveStopOrder(
                    $symbol,
                    Side::Buy,
                    $secondLongStopId,
                    $secondLongStopQty,
                    $secondLongStopTriggerPrice,
                    $secondLongStopTriggerBy->value
                ),
                # active SHORT conditional stops
                new ActiveStopOrder(
                    $symbol,
                    Side::Sell,
                    $shortStopId,
                    $shortStopQty,
                    $shortStopTriggerPrice,
                    $shortStopTriggerBy->value
                ),
            ]
        ];
    }

    /**
     * @dataProvider closeOrderFailTestCases
     */
    public function testFailGetActiveConditionalOrders(
        AssetCategory $category,
        Symbol $symbol,
        Side $positionSide,
        MockResponse $apiResponse,
        Throwable $expectedException,
    ): void {
        // Arrange
        $orderId = uuid_create();
        $this->matchGet(GetCurrentOrdersRequest::openOnly($category, $symbol), $apiResponse);

        $exception = null;

        // Act
        try {
            $this->service->activeConditionalOrders($symbol);
        } catch (Throwable $exception) {
        }

        // Assert
        self::assertEquals($expectedException, $exception);
    }

    private function closeOrderFailTestCases(): iterable
    {
        $category = self::ASSET_CATEGORY;
        $symbol = Symbol::BTCUSDT;

        # Ticker not found
        foreach ($this->positionSideProvider() as [$side]) {
            # Api errors
            $error = ByBitV5ApiError::knownError(ApiV5Errors::ApiRateLimitReached, $msg = 'Api rate limit reached');
            yield sprintf('[%s] API returned %d code (%s)', $side->value, $error->code(), ApiV5Errors::ApiRateLimitReached->desc()) => [
                $category, $symbol, $side,
                '$apiResponse' => CurrentOrdersResponseBuilder::error($category, $error)->build(),
                '$expectedException' => new ApiRateLimitReached($msg),
            ];

            $error = ByBitV5ApiError::unknown(100500, 'Some other get current orders request error');
            yield sprintf('[%s] API returned unknown %d code (%s) => UnknownByBitApiErrorException', $side->value, $error->code(), $error->msg()) => [
                $category, $symbol, $side,
                '$apiResponse' => CurrentOrdersResponseBuilder::error($category, $error)->build(),
                '$expectedException' => self::unknownV5ApiErrorException(self::REQUEST_URL, $error),
            ];

            $error = ByBitV5ApiError::knownError(ApiV5Errors::CannotAffordOrderCost, ApiV5Errors::CannotAffordOrderCost->desc());
            yield sprintf('[%s] API returned known %d code (%s) => UnexpectedApiErrorException', $side->value, $error->code(), $error->msg()) => [
                $category, $symbol, $side,
                '$apiResponse' => CurrentOrdersResponseBuilder::error($category, $error)->build(),
                '$expectedException' => self::unexpectedV5ApiErrorException(self::REQUEST_URL, $error, self::CALLED_METHOD),
            ];
        }
    }
}
