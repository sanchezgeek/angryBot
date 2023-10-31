<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\Trade;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Infrastructure\ByBit\Service\Trade\ByBitOrderService;
use App\Tests\Functional\Infrastructure\BybBit\ByBitApiServiceTestAbstract;
use App\Tests\Functional\Infrastructure\BybBit\Service\ApiErrorTestCaseData;
use App\Tests\Functional\Infrastructure\BybBit\Service\ApiTestCaseData;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mixin\DataProvider\TestCaseAwareTest;
use App\Tests\Mock\Response\ByBitV5Api\PlaceOrderResponseBuilder;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

use function array_merge;

final class ByBitOrderServiceTest extends ByBitApiServiceTestAbstract
{
    use PositionSideAwareTest;
    use TestCaseAwareTest;

    private const REQUEST_URL = PlaceOrderRequest::URL;
    private const CALLED_METHOD = 'ByBitOrderService::closeByMarket';

    private ByBitOrderService $service;

    protected function setUp(): void
    {
        $this->service = new ByBitOrderService(
            $this->initializeApiClient()
        );
    }

    /**
     * @dataProvider closeByMarketSuccessCases
     *
     * @param array{category: AssetCategory, symbol: Symbol, positionSide: Side} $data
     */
    public function testCanCloseByMarket(array $data): void
    {
        // Arrange
        $category = $data['category']; $symbol = $data['symbol']; $positionSide = $data['positionSide'];
        $position = self::makePosition($symbol, $positionSide);
        $placedExchangeOrderId = uuid_create();

        $this->matchPost(
            PlaceOrderRequest::marketClose($category, $symbol, $positionSide, $orderQty = 0.01),
            PlaceOrderResponseBuilder::ok($placedExchangeOrderId)->build()
        );

        // Act
        $result = $this->service->closeByMarket($position, $orderQty);

        // Assert
        self::assertSame($placedExchangeOrderId, $result);
    }

    private function closeByMarketSuccessCases(): iterable
    {
        return $this->testCasesIterator(
            $this->positionSideIterator(static function (Side $side) {
                return ApiTestCaseData::linearBtcUsdt()->with(['positionSide' => $side]);
            })
        );
    }

    /**
     * @dataProvider failedTestCases
     *
     * @param array{category: AssetCategory, symbol: Symbol, positionSide: Side, apiResponse: MockResponse, expectedException: Throwable} $data
     */
    public function testFailCloseByMarket(array $data): void
    {
        // Arrange
        $category = $data['category']; $symbol = $data['symbol']; $positionSide = $data['positionSide'];
        $position = self::makePosition($symbol, $positionSide);

        $expectedRequest = PlaceOrderRequest::marketClose($category, $symbol, $positionSide, $orderQty = 0.01);
        $this->matchPost($expectedRequest, $data['apiResponse']);

        // Act
        try {
            $this->service->closeByMarket($position, $orderQty);
        } catch (Throwable $exception) {}

        // Assert
        self::assertEquals($data['expectedException'], $exception ?? null);
    }

    protected function failedTestCases(): iterable
    {
        $category = AssetCategory::linear; $symbol = Symbol::BTCUSDT;

        $apiErrorTestCases = array_merge($this->commonFailedApiCallCases(self::REQUEST_URL), [
            ApiErrorTestCaseData::knownApiError(
                $error = ApiV5Errors::MaxActiveCondOrdersQntReached,
                $msg = 'Max orders',
                self::unexpectedException(self::REQUEST_URL, $error->code(), $msg, self::CALLED_METHOD)
            )
        ]);

        $cases = [];
        foreach ($apiErrorTestCases as $apiErrorCase) {
            foreach (self::POSITION_SIDES as $side) {
                $cases[] = $apiErrorCase->with(['category' => $category, 'symbol' => $symbol, 'positionSide' => $side]);
            }
        }

        return $this->testCasesIterator($cases);
    }
}
