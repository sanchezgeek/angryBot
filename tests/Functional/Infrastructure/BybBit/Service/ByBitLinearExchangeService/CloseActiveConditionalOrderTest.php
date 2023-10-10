<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService;

use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Result\CommonApiError;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Error;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Trade\CancelOrderRequest;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use App\Tests\Mock\Response\ByBit\Trade\CancelOrderResponseBuilder;
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
    private const METHOD = ByBitLinearExchangeService::class . '::closeActiveConditionalOrder';

    /**
     * @dataProvider closeOrderTestSuccessCases
     */
    public function testCloseActiveConditionalOrder(
        AssetCategory $category,
        Symbol $symbol,
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

        $symbol = Symbol::BTCUSDT;

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
        Symbol $symbol,
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
        $symbol = Symbol::BTCUSDT;

        # Ticker not found
        foreach ($this->positionSideProvider() as [$side]) {
            # Api errors
            $error = ApiV5Error::ApiRateLimitReached;
            yield sprintf('[%s] API returned %d code (%s)', $side->value, $error->code(), $error->desc()) => [
                $category, $symbol, $side,
                '$apiResponse' => CancelOrderResponseBuilder::error($error)->build(),
                '$expectedException' => new ApiRateLimitReached(),
            ];

            $error = new CommonApiError(100500, 'Some other cancel order error');
            yield sprintf('[%s] API returned %d code (%s)', $side->value, $error->code(), $error->desc()) => [
                $category, $symbol, $side,
                '$apiResponse' => CancelOrderResponseBuilder::error($error)->build(),
                '$expectedException' => self::expectedUnknownApiErrorException(self::REQUEST_URL, $error, self::METHOD),
            ];
        }
    }
}
