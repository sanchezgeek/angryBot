<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService;

use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiError;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Request\Trade\CancelOrderRequest;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use App\Tests\Mock\Response\ByBitV5Api\Trade\CancelOrderResponseBuilder;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;
use function sprintf;
use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\Service\ByBitLinearExchangeService::closeActiveConditionalOrder
 */
final class CloseActiveConditionalOrderTest extends ByBitLinearExchangeServiceTestAbstract
{
    use PositionSideAwareTest;
    use ByBitV5ApiTester;

    private const REQUEST_URL = CancelOrderRequest::URL;
    private const CALLED_METHOD = 'ByBitLinearExchangeService::closeActiveConditionalOrder';

    /**
     * @dataProvider closeOrderTestSuccessCases
     */
    public function testCloseActiveConditionalOrder(
        AssetCategory $category,
        SymbolInterface $symbol,
        Side $positionSide,
    ): void {
        // Arrange
        $orderId = uuid_create();
        $apiResponse = CancelOrderResponseBuilder::ok($orderId)->build();
        $this->matchPost(CancelOrderRequest::byOrderId($category, $symbol, $orderId), $apiResponse);

        // Act
        $this->service->closeActiveConditionalOrder(
            new ActiveStopOrder($symbol, $positionSide, $orderId, 0.01, 30000, 'IndexPrice')
        );

        // Nothing to assert (void method)
    }

    private function closeOrderTestSuccessCases(): iterable
    {
        $category = self::ASSET_CATEGORY;

        $symbol = SymbolEnum::BTCUSDT;

        foreach ($this->positionSideProvider() as [$side]) {
            yield sprintf('close %s %s ticker (%s)', $symbol->value, $side->value, $category->value) => [
                $category, $symbol, $side,
            ];
        }
    }

    /**
     * @dataProvider closeOrderFailTestCases
     */
    public function testFailCloseActiveConditionalOrder(
        AssetCategory $category,
        SymbolInterface $symbol,
        Side $positionSide,
        MockResponse $apiResponse,
        Throwable $expectedException,
    ): void {
        // Arrange
        $orderId = uuid_create();
        $this->matchPost(CancelOrderRequest::byOrderId($category, $symbol, $orderId), $apiResponse);

        $exception = null;

        // Act
        try {
            $this->service->closeActiveConditionalOrder(
                new ActiveStopOrder($symbol, $positionSide, $orderId, 0.01, 30000, 'IndexPrice')
            );
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
                '$apiResponse' => CancelOrderResponseBuilder::error($error)->build(),
                '$expectedException' => new ApiRateLimitReached($msg),
            ];

            $error = ByBitV5ApiError::unknown(100500, 'Some other cancel order error');
            yield sprintf('[%s] API returned unknown %d code (%s) => UnknownByBitApiErrorException', $side->value, $error->code(), $error->msg()) => [
                $category, $symbol, $side,
                '$apiResponse' => CancelOrderResponseBuilder::error($error)->build(),
                '$expectedException' => self::unknownV5ApiErrorException(self::REQUEST_URL, $error),
            ];

            $error = ByBitV5ApiError::knownError(ApiV5Errors::MaxActiveCondOrdersQntReached, ApiV5Errors::MaxActiveCondOrdersQntReached->desc());
            yield sprintf('[%s] API returned known %d code (%s) => UnexpectedApiErrorException', $side->value, $error->code(), $error->msg()) => [
                $category, $symbol, $side,
                '$apiResponse' => CancelOrderResponseBuilder::error($error)->build(),
                '$expectedException' => self::unexpectedV5ApiErrorException(self::REQUEST_URL, $error, self::CALLED_METHOD),
            ];
        }
    }
}
