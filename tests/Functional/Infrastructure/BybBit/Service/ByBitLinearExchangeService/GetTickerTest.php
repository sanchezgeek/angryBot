<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService;

use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiError;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest;
use App\Infrastructure\ByBit\Service\Exception\Market\TickerNotFoundException;
use App\Tests\Assertion\CustomAssertions;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use App\Tests\Mock\Response\ByBitV5Api\MarketResponseBuilder;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

use function sprintf;

/**
 * @covers \App\Infrastructure\ByBit\Service\ByBitLinearExchangeService::ticker
 */
final class GetTickerTest extends ByBitLinearExchangeServiceTestAbstract
{
    use ByBitV5ApiTester;

    private const REQUEST_URL = GetTickersRequest::URL;
    private const CALLED_METHOD = 'ByBitLinearExchangeService::ticker';

    /**
     * @dataProvider getTickerTestSuccessCases
     */
    public function testCanGetTicker(
        SymbolInterface $symbol,
        AssetCategory $category,
        MockResponse $apiResponse,
        Ticker $expectedTicker
    ): void {
        // Arrange
        $this->matchGet(new GetTickersRequest($category, $symbol), $apiResponse);

        // Act
        $ticker = $this->service->ticker($symbol);

        // Assert
        CustomAssertions::assertEqualsWithInnerSymbols($expectedTicker, $ticker);
    }

    private function getTickerTestSuccessCases(): iterable
    {
        $category = self::ASSET_CATEGORY;

        $symbol = SymbolEnum::BTCUSDT;
        yield sprintf('have %s ticker (%s)', $symbol->name(), $category->value) => [
            $symbol, $category,
            '$apiResponse' => MarketResponseBuilder::ok($category)->withTicker(
                $symbol,
                $indexPrice = 30000,
                $lastPrice = 29950,
                $markPrice = 29990,
            )->build(),
            '$expectedTicker' => TickerFactory::create($symbol, $indexPrice, $markPrice, $lastPrice),
        ];

        $symbol = SymbolEnum::BTCUSD;
        yield sprintf('have %s ticker (%s)', $symbol->name(), $category->value) => [
            $symbol, $category,
            '$apiResponse' => MarketResponseBuilder::ok($category)->withTicker(
                $symbol,
                $indexPrice = 31000,
                $lastPrice = 30980,
                $markPrice = 30990,
            )->build(),
            '$expectedTicker' => TickerFactory::create($symbol, $indexPrice, $markPrice, $lastPrice),
        ];
    }

    /**
     * @dataProvider getTickerFailTestCases
     */
    public function testFailGetTickerWhenTickerNotFoundThroughApi(
        SymbolInterface $symbol,
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
        $symbol = SymbolEnum::BTCUSDT;
        $notFoundExpected = TickerNotFoundException::forSymbolAndCategory($symbol, $category);
        yield sprintf('have %s ticker (%s), but request %s ticker => %s', SymbolEnum::BTCUSD->value, $category->value, $symbol->name(), $notFoundExpected->getMessage()) => [
            $symbol, $category,
            '$apiResponse' => MarketResponseBuilder::ok($category)->withTicker(SymbolEnum::BTCUSD, 30000, 29980, 29990)->build(),
            '$expectedException' => $notFoundExpected,
        ];

        $symbol = SymbolEnum::BTCUSD;
        $notFoundExpected = TickerNotFoundException::forSymbolAndCategory($symbol, $category);
        yield sprintf('have %s ticker (%s), but request %s ticker => %s', SymbolEnum::BTCUSDT->value, $category->value, $symbol->name(), $notFoundExpected->getMessage()) => [
            $symbol, $category,
            '$apiResponse' => MarketResponseBuilder::ok($category)->withTicker(SymbolEnum::BTCUSDT, 30000, 29980, 29990)->build(),
            '$expectedException' => $notFoundExpected,
        ];

        # Api errors
        $symbol = SymbolEnum::BTCUSDT;
        $error = ByBitV5ApiError::knownError(ApiV5Errors::ApiRateLimitReached, $msg = 'Api rate limit reached');
        yield sprintf('API returned %d code (%s)', $error->code(), ApiV5Errors::ApiRateLimitReached->desc()) => [
            $symbol, $category,
            '$apiResponse' => MarketResponseBuilder::error($category, $error)->build(),
            '$expectedException' => new ApiRateLimitReached($msg),
        ];

        $error = ByBitV5ApiError::unknown(100500, 'Some other error');
        yield sprintf('API returned %d code (%s) => UnknownByBitApiErrorException', $error->code(), $error->msg()) => [
            $symbol, $category,
            '$apiResponse' => MarketResponseBuilder::error($category, $error)->build(),
            '$expectedException' => self::unknownV5ApiErrorException(self::REQUEST_URL, $error),
        ];

        $error = ByBitV5ApiError::knownError(ApiV5Errors::MaxActiveCondOrdersQntReached, ApiV5Errors::MaxActiveCondOrdersQntReached->desc());
        yield sprintf('API returned known %d code (%s) => UnexpectedApiErrorException', $error->code(), $error->msg()) => [
            $symbol, $category,
            '$apiResponse' => MarketResponseBuilder::error($category, $error)->build(),
            '$expectedException' => self::unexpectedV5ApiErrorException(self::REQUEST_URL, $error, self::CALLED_METHOD),
        ];
    }
}
