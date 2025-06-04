<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService;

use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\ValueObject\Order\ExecutionOrderType;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\PriceRange;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiError;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Request\Trade\GetCurrentOrdersRequest;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mixin\SymbolsDependentTester;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use App\Tests\Mock\Response\ByBitV5Api\Trade\CurrentOrdersResponseBuilder;
use App\Trading\Domain\Symbol\SymbolInterface;
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
    use SymbolsDependentTester;

    private const REQUEST_URL = GetCurrentOrdersRequest::URL;
    private const CALLED_METHOD = 'ByBitLinearExchangeService::activeConditionalOrders';

    /**
     * @dataProvider getActiveConditionalOrdersTestSuccessCases
     */
    public function testCanGetActiveConditionalOrders(
        AssetCategory $category,
        ?SymbolInterface $symbol,
        ?PriceRange $priceRange,
        MockResponse $apiResponse,
        array $expectedActiveStopOrders
    ): void {
        // Arrange
        $this->matchGet(GetCurrentOrdersRequest::openOnly($category, $symbol), $apiResponse);

        // Act
        $activeConditionalOrders = $this->service->activeConditionalOrders($symbol, $priceRange);

        // Assert
        self::assertOrdersEqual($expectedActiveStopOrders, $activeConditionalOrders);
    }

    private function getActiveConditionalOrdersTestSuccessCases(): iterable
    {
        $category = self::ASSET_CATEGORY;

        $mockResponse = CurrentOrdersResponseBuilder::ok($category)
            # active LONG conditional stops
            ->withOrder(
                SymbolEnum::BTCUSDT,
                Side::Sell,
                $firstLongStopId = uuid_create(),
                $firstLongStopTriggerPrice = 30000,
                $firstLongStopQty = 0.1,
                $firstLongStopTriggerBy = TriggerBy::IndexPrice,
                ExecutionOrderType::Market,
                true,
                false,
            )
            ->withOrder(
                SymbolEnum::BTCUSDT,
                Side::Sell,
                $secondLongStopId = uuid_create(),
                $secondLongStopTriggerPrice = 32000,
                $secondLongStopQty = 0.3,
                $secondLongStopTriggerBy = TriggerBy::MarkPrice,
                ExecutionOrderType::Market,
                true,
                false,
            )
            # active BTCUSDT SHORT conditional stops
            ->withOrder(
                SymbolEnum::BTCUSDT,
                Side::Buy,
                $shortStopId = uuid_create(),
                $shortStopTriggerPrice = 33000,
                $shortStopQty = 0.4,
                $shortStopTriggerBy = TriggerBy::LastPrice,
                ExecutionOrderType::Market,
                true,
                false,
            )
            # not conditional stops
            ->withOrder(
                SymbolEnum::BTCUSDT,
                Side::Sell,
                uuid_create(),
                31000,
                0.01,
                TriggerBy::IndexPrice,
                ExecutionOrderType::Market,
                false,
                false,
            )
            # `Limit` orders
            ->withOrder(
                SymbolEnum::BTCUSDT,
                Side::Sell,
                uuid_create(),
                31100,
                0.01,
                TriggerBy::IndexPrice,
                ExecutionOrderType::Limit,
                true,
                false,
            )
            # active VIRTUALUSDT LONG conditional stops
            ->withOrder(
                SymbolEnum::VIRTUALUSDT,
                Side::Sell,
                $virtualLongStopId = uuid_create(),
                $virtualLongStopTriggerPrice = 0.2,
                $virtualLongStopQty = 10,
                $virtualLongStopTriggerBy = TriggerBy::LastPrice,
                ExecutionOrderType::Market,
                true,
                false,
            )
            # active VIRTUALUSDT SHORT conditional stops
            ->withOrder(
                SymbolEnum::VIRTUALUSDT,
                Side::Buy,
                $virtualShortStopId = uuid_create(),
                $virtualShortStopTriggerPrice = 0.7,
                $virtualShortStopQty = 20,
                $virtualShortStopTriggerBy = TriggerBy::LastPrice,
                ExecutionOrderType::Market,
                true,
                false,
            )
            ->build();

        #  with symbol
        yield sprintf('get active %s stops (%s) without PriceRange specified', SymbolEnum::BTCUSDT->value, $category->value) => [
            $category, SymbolEnum::BTCUSDT,
            '$priceRange' => null,
            '$apiResponse' => $mockResponse,
            '$expectedActiveOrders' => [
                # active LONG conditional stops
                $firstLongStopId => new ActiveStopOrder(
                    SymbolEnum::BTCUSDT,
                    Side::Buy,
                    $firstLongStopId,
                    $firstLongStopQty,
                    $firstLongStopTriggerPrice,
                    $firstLongStopTriggerBy->value
                ),
                $secondLongStopId => new ActiveStopOrder(
                    SymbolEnum::BTCUSDT,
                    Side::Buy,
                    $secondLongStopId,
                    $secondLongStopQty,
                    $secondLongStopTriggerPrice,
                    $secondLongStopTriggerBy->value
                ),
                # active SHORT conditional stops
                $shortStopId => new ActiveStopOrder(
                    SymbolEnum::BTCUSDT,
                    Side::Sell,
                    $shortStopId,
                    $shortStopQty,
                    $shortStopTriggerPrice,
                    $shortStopTriggerBy->value
                ),
            ]
        ];

        yield sprintf('get active %s stops (%s) without PriceRange specified', SymbolEnum::VIRTUALUSDT->value, $category->value) => [
            $category, SymbolEnum::VIRTUALUSDT,
            '$priceRange' => null,
            '$apiResponse' => $mockResponse,
            '$expectedActiveOrders' => [
                $virtualLongStopId => new ActiveStopOrder(
                    SymbolEnum::VIRTUALUSDT,
                    Side::Buy,
                    $virtualLongStopId,
                    $virtualLongStopQty,
                    $virtualLongStopTriggerPrice,
                    $virtualLongStopTriggerBy->value
                ),
                $virtualShortStopId => new ActiveStopOrder(
                    SymbolEnum::VIRTUALUSDT,
                    Side::Sell,
                    $virtualShortStopId,
                    $virtualShortStopQty,
                    $virtualShortStopTriggerPrice,
                    $virtualShortStopTriggerBy->value
                ),
            ]
        ];

        # with price range specified
        yield sprintf('get active %s stops (%s) with PriceRange specified', SymbolEnum::BTCUSDT->value, $category->value) => [
            $category, SymbolEnum::BTCUSDT,
            '$priceRange' => PriceRange::create(31500, 32001, SymbolEnum::BTCUSDT),
            '$apiResponse' => $mockResponse,
            '$expectedActiveOrders' => [
                $secondLongStopId => new ActiveStopOrder(
                    SymbolEnum::BTCUSDT,
                    Side::Buy,
                    $secondLongStopId,
                    $secondLongStopQty,
                    $secondLongStopTriggerPrice,
                    $secondLongStopTriggerBy->value
                ),
            ]
        ];

        yield sprintf('get active %s stops (%s) with PriceRange specified', SymbolEnum::VIRTUALUSDT->value, $category->value) => [
            $category, SymbolEnum::VIRTUALUSDT,
            '$priceRange' => PriceRange::create(0.1, 0.3, SymbolEnum::VIRTUALUSDT),
            '$apiResponse' => $mockResponse,
            '$expectedActiveOrders' => [
                $virtualLongStopId => new ActiveStopOrder(
                    SymbolEnum::VIRTUALUSDT,
                    Side::Buy,
                    $virtualLongStopId,
                    $virtualLongStopQty,
                    $virtualLongStopTriggerPrice,
                    $virtualLongStopTriggerBy->value
                ),
            ]
        ];

        #  without symbol
        yield 'without symbol specified' => [
            $category, null,
            '$priceRange' => null,
            '$apiResponse' => $mockResponse,
            '$expectedActiveOrders' => [
                # BTCUSDT
                $firstLongStopId => new ActiveStopOrder(
                    SymbolEnum::BTCUSDT,
                    Side::Buy,
                    $firstLongStopId,
                    $firstLongStopQty,
                    $firstLongStopTriggerPrice,
                    $firstLongStopTriggerBy->value
                ),
                $secondLongStopId => new ActiveStopOrder(
                    SymbolEnum::BTCUSDT,
                    Side::Buy,
                    $secondLongStopId,
                    $secondLongStopQty,
                    $secondLongStopTriggerPrice,
                    $secondLongStopTriggerBy->value
                ),
                # active SHORT conditional stops
                $shortStopId => new ActiveStopOrder(
                    SymbolEnum::BTCUSDT,
                    Side::Sell,
                    $shortStopId,
                    $shortStopQty,
                    $shortStopTriggerPrice,
                    $shortStopTriggerBy->value
                ),

                # VIRTUALUSDT
                $virtualLongStopId => new ActiveStopOrder(
                    SymbolEnum::VIRTUALUSDT,
                    Side::Buy,
                    $virtualLongStopId,
                    $virtualLongStopQty,
                    $virtualLongStopTriggerPrice,
                    $virtualLongStopTriggerBy->value
                ),
                $virtualShortStopId => new ActiveStopOrder(
                    SymbolEnum::VIRTUALUSDT,
                    Side::Sell,
                    $virtualShortStopId,
                    $virtualShortStopQty,
                    $virtualShortStopTriggerPrice,
                    $virtualShortStopTriggerBy->value
                ),
            ]
        ];
    }

    /**
     * @dataProvider closeOrderFailTestCases
     */
    public function testFailGetActiveConditionalOrders(
        AssetCategory $category,
        SymbolInterface $symbol,
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
        $symbol = SymbolEnum::BTCUSDT;

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
