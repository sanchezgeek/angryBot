<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\ByBitLinearExchangeService;

use App\Bot\Application\Exception\ApiRateLimitReached;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\Result\CommonApiError;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Error;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest;
use App\Infrastructure\ByBit\ByBitLinearExchangeService;
use App\Infrastructure\ByBit\Exception\ByBitTickerNotFoundException;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use App\Tests\Mock\Response\ByBit\MarketResponseBuilder;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

use function sprintf;

/**
 * @covers \App\Infrastructure\ByBit\ByBitLinearExchangeService::ticker
 */
final class GetTickerTest extends ByBitLinearExchangeServiceTestAbstract
{
    use ByBitV5ApiTester;

    private const REQUEST_URL = GetTickersRequest::URL;
    private const METHOD = ByBitLinearExchangeService::class . '::ticker';

    /**
     * @dataProvider getTickerTestSuccessCases
     */
    public function testCanGetTicker(
        Symbol $symbol,
        AssetCategory $category,
        MockResponse $apiResponse,
        Ticker $expectedTicker
    ): void {
        // Arrange
        $this->matchGet(new GetTickersRequest($category, $symbol), $apiResponse);

        // Act
        $ticker = $this->service->ticker($symbol);

        // Assert
        self::assertEquals($expectedTicker, $ticker);
    }

    private function getTickerTestSuccessCases(): iterable
    {
        $category = self::ASSET_CATEGORY;

        $symbol = Symbol::BTCUSDT;
        yield sprintf('have %s ticker (%s)', $symbol->value, $category->value) => [
            $symbol, $category,
            '$apiResponse' => MarketResponseBuilder::ok($category)->withTicker(
                $symbol,
                $indexPrice = 30000,
                $lastPrice = 29980,
                $markPrice = 29990,
            )->build(),
            '$expectedPosition' => new Ticker($symbol, $markPrice, $indexPrice, self::WORKER_DEBUG_HASH),
        ];

        $symbol = Symbol::BTCUSD;
        yield sprintf('have %s ticker (%s)', $symbol->value, $category->value) => [
            $symbol, $category,
            '$apiResponse' => MarketResponseBuilder::ok($category)->withTicker(
                $symbol,
                $indexPrice = 31000,
                $lastPrice = 30980,
                $markPrice = 30990,
            )->build(),
            '$expectedPosition' => new Ticker($symbol, $markPrice, $indexPrice, self::WORKER_DEBUG_HASH),
        ];
    }

    /**
     * @dataProvider getTickerFailTestCases
     */
    public function testFailGetTickerWhenTickerNotFoundThroughApi(
        Symbol $symbol,
        AssetCategory $category,
        MockResponse $apiResponse,
        Throwable $expectedException,
    ): void {
        // Arrange
        $this->matchGet(new GetTickersRequest($category, $symbol), $apiResponse);

        $exception = null;

        // Act
        try {
            $this->service->ticker($symbol);
        } catch (Throwable $exception) {
        }

        // Assert
        self::assertEquals($expectedException, $exception);
    }

    private function getTickerFailTestCases(): iterable
    {
        $category = self::ASSET_CATEGORY;

        # Ticker not found
        $symbol = Symbol::BTCUSDT;
        $notFoundExpected = ByBitTickerNotFoundException::forSymbolAndCategory($symbol, $category);
        yield sprintf('have %s ticker (%s), but request %s ticker => %s', Symbol::BTCUSD->value, $category->value, $symbol->value, $notFoundExpected->getMessage()) => [
            $symbol, $category,
            '$apiResponse' => MarketResponseBuilder::ok($category)->withTicker(Symbol::BTCUSD, 30000, 29980, 29990)->build(),
            '$expectedException' => $notFoundExpected,
        ];

        $symbol = Symbol::BTCUSD;
        $notFoundExpected = ByBitTickerNotFoundException::forSymbolAndCategory($symbol, $category);
        yield sprintf('have %s ticker (%s), but request %s ticker => %s', Symbol::BTCUSDT->value, $category->value, $symbol->value, $notFoundExpected->getMessage()) => [
            $symbol, $category,
            '$apiResponse' => MarketResponseBuilder::ok($category)->withTicker(Symbol::BTCUSDT, 30000, 29980, 29990)->build(),
            '$expectedException' => $notFoundExpected,
        ];

        # Api errors
        $symbol = Symbol::BTCUSDT;
        $error = ApiV5Error::ApiRateLimitReached;
        yield sprintf('API returned %d code (%s)', $error->code(), $error->desc()) => [
            $symbol, $category,
            '$apiResponse' => MarketResponseBuilder::error($category, $error)->build(),
            '$expectedException' => new ApiRateLimitReached(),
        ];

        $error = new CommonApiError(100500, 'Some other error');
        yield sprintf('API returned %d code (%s)', $error->code(), $error->desc()) => [
            $symbol, $category,
            '$apiResponse' => MarketResponseBuilder::error($category, $error)->build(),
            '$expectedException' => self::expectedUnknownApiErrorException(self::REQUEST_URL, $error, self::METHOD),
        ];
    }
}
