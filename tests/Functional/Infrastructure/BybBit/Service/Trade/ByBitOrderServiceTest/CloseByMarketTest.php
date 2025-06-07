<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\Trade\ByBitOrderServiceTest;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Functional\Infrastructure\BybBit\Service\ApiErrorTestCaseData;
use App\Tests\Functional\Infrastructure\BybBit\Service\ApiTestCaseData;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mixin\DataProvider\TestCaseAwareTest;
use App\Tests\Mock\Response\ByBitV5Api\PlaceOrderResponseBuilder;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

/**
 * @covers \App\Infrastructure\ByBit\Service\Trade\ByBitOrderService::closeByMarket
 */
final class CloseByMarketTest extends ByBitOrderServiceTestAbstract
{
    use PositionSideAwareTest;
    use TestCaseAwareTest;

    private const REQUEST_URL = PlaceOrderRequest::URL;
    private const CALLED_METHOD = 'ByBitOrderService::closeByMarket';

    /**
     * @dataProvider closeByMarketSuccessCases
     *
     * @param array{category: AssetCategory, symbol: SymbolInterface, positionSide: Side} $data
     */
    public function testCanCloseByMarket(array $data): void
    {
        $category = $data['category']; $symbol = $data['symbol']; $positionSide = $data['positionSide'];
        $position = PositionBuilder::bySide($positionSide)->symbol($symbol)->build();

        $this->matchPost(
            PlaceOrderRequest::marketClose($category, $symbol, $positionSide, $orderQty = 0.01),
            PlaceOrderResponseBuilder::ok($placedExchangeOrderId = uuid_create())->build()
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
     * @param array{category: AssetCategory, symbol: SymbolInterface, positionSide: Side, apiResponse: MockResponse, expectedException: Throwable} $data
     */
    public function testFailCloseByMarket(array $data): void
    {
        // Arrange
        $category = $data['category']; $symbol = $data['symbol']; $positionSide = $data['positionSide'];
        $position = PositionBuilder::bySide($positionSide)->symbol($symbol)->build();

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
        $category = AssetCategory::linear;
        $symbol = SymbolEnum::BTCUSDT;

        # common errors
        $apiErrorTestCases = $this->commonFailedApiCallCases(self::REQUEST_URL);

        # some unexpected errors
        $error = ApiV5Errors::MaxActiveCondOrdersQntReached;
        $apiErrorTestCases[] = ApiErrorTestCaseData::knownApiError($error, $msg = 'Max orders', self::unexpectedApiErrorError(self::REQUEST_URL, $error->code(), $msg, self::CALLED_METHOD));

        $cases = [];
        foreach ($apiErrorTestCases as $apiErrorCase) {
            foreach (self::POSITION_SIDES as $side) {
                $cases[] = $apiErrorCase->with(['category' => $category, 'symbol' => $symbol, 'positionSide' => $side]);
            }
        }

        return $this->testCasesIterator($cases);
    }
}
