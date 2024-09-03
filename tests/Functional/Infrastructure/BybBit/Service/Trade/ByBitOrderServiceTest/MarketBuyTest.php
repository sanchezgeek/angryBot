<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\Trade\ByBitOrderServiceTest;

use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Tests\Functional\Infrastructure\BybBit\Service\ApiErrorTestCaseData;
use App\Tests\Functional\Infrastructure\BybBit\Service\ApiTestCaseData;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mixin\DataProvider\TestCaseAwareTest;
use App\Tests\Mock\Response\ByBitV5Api\PlaceOrderResponseBuilder;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\Service\Trade\ByBitOrderService::marketBuy
 */
final class MarketBuyTest extends ByBitOrderServiceTestAbstract
{
    use PositionSideAwareTest;
    use TestCaseAwareTest;

    private const ORDER_QTY = 0.01;

    private const REQUEST_URL = PlaceOrderRequest::URL;
    private const CALLED_METHOD = 'ByBitOrderService::marketBuy';

    /**
     * @dataProvider marketBuySuccessTestCases
     *
     * @param array{category: AssetCategory, symbol: Symbol, positionSide: Side} $data
     */
    public function testCanDoMarketBuy(array $data): void
    {
        $this->matchPost(
            PlaceOrderRequest::marketBuy($data['category'], $data['symbol'], $data['positionSide'], $qty = 0.01),
            PlaceOrderResponseBuilder::ok($placedExchangeOrderId = uuid_create())->build()
        );

        // Act
        $result = $this->service->marketBuy($data['symbol'], $data['positionSide'], $qty);

        // Assert
        self::assertSame($placedExchangeOrderId, $result);
    }

    private function marketBuySuccessTestCases(): iterable
    {
        return $this->testCasesIterator(
            $this->positionSideIterator(static function (Side $side) {
                return ApiTestCaseData::linearBtcUsdt()->with(['positionSide' => $side]);
            })
        );
    }

    /**
     * @dataProvider marketBuyFailTestCases
     *
     * @param array{category: AssetCategory, symbol: Symbol, positionSide: Side, apiResponse: MockResponse, expectedException: Throwable} $data
     */
    public function testFailAddBuyOrder(array $data): void
    {
        $this->matchPost(PlaceOrderRequest::marketBuy(
            $data['category'],
            $data['symbol'],
            $data['positionSide'],
            $qty = self::ORDER_QTY,
        ), $data['apiResponse']);

        // Act
        try {
            $this->service->marketBuy($data['symbol'], $data['positionSide'], $qty);
        } catch (Throwable $exception) {
        }

        // Assert
        self::assertEquals($data['expectedException'], $exception ?? null);
    }

    private function marketBuyFailTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $category = AssetCategory::linear;

        # common errors
        $apiErrorTestCases = $this->commonFailedApiCallCases(self::REQUEST_URL);

        # some unexpected errors
        $apiErrorTestCases[] = ApiErrorTestCaseData::knownApiError(
            $error = ApiV5Errors::MaxActiveCondOrdersQntReached,
            $msg = 'Max orders limit reached',
            self::unexpectedApiErrorError(self::REQUEST_URL, $error->code(), $msg, self::CALLED_METHOD),
        );

        $cases = [];
        foreach (self::POSITION_SIDES as $side) {
            foreach ($apiErrorTestCases as $apiErrorCase) {
                $cases[] = $apiErrorCase->with(['category' => $category, 'symbol' => $symbol, 'positionSide' => $side]);
            }

            # market buy errors
            $cases[] = ApiErrorTestCaseData::knownApiError(
                ApiV5Errors::CannotAffordOrderCost,
                'Cannot afford',
                CannotAffordOrderCostException::forBuy($symbol, $side, self::ORDER_QTY),
            )->with(['category' => $category, 'symbol' => $symbol, 'positionSide' => $side]);
        }

        return $this->testCasesIterator($cases);
    }
}
