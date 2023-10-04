<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiClient;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Infrastructure\ByBit\ByBitLinearPositionService;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mock\Response\ByBit\PositionResponseBuilder;
use App\Tests\Mock\Response\ByBit\TradeResponseBuilder;
use App\Tests\Stub\Request\SymfonyHttpClientStub;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use Symfony\Component\HttpClient\Response\MockResponse;

use function sprintf;
use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\ByBitLinearPositionService
 */
final class ByBitLinearPositionServiceTest extends KernelTestCase
{
    private const HOST = 'https://api-testnet.bybit.com';
    private const API_KEY = 'bybit-api-key';
    private const API_SECRET = 'bybit-api-secret';

    private SymfonyHttpClientStub $httpClientStub;

    private ByBitLinearPositionService $service;

    protected function setUp(): void
    {
        $clockMock = $this->createMock(ClockInterface::class);
        $clockMock->method('now')->willReturn(new DateTimeImmutable());

        $this->httpClientStub = new SymfonyHttpClientStub(self::HOST);

        $this->service = new ByBitLinearPositionService(
            // @todo | tests | create client with factory (for all tests)
            // @todo | tests | make some kind of mixin to work with api
            new ByBitV5ApiClient(
                $this->httpClientStub,
                $clockMock,
                self::HOST,
                self::API_KEY,
                self::API_SECRET,
            )
        );
    }

    /**
     * @dataProvider getPositionTestCases
     */
    public function testGetPosition(
        Symbol $symbol,
        AssetCategory $category,
        Side $positionSide,
        MockResponse $apiResponse,
        ?Position $expectedPosition
    ): void {
        // Arrange
        $expectedRequest = new GetPositionsRequest($category, $symbol);
        $requestUrl = $this->getFullRequestUrl($expectedRequest);
        $this->httpClientStub->matchGet($requestUrl, $expectedRequest->data(), $apiResponse);

        // Act
        $position = $this->service->getPosition($symbol, $positionSide);

        // Assert
        self::assertEquals($expectedPosition, $position);
    }

    private function getPositionTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $category = AssetCategory::linear;
        $positionSide = Side::Sell;

        yield sprintf('have %s %s position (%s)', $symbol->value, $positionSide->title(), $category->value) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => (new PositionResponseBuilder($category))->addPosition(
                $symbol,
                $positionSide,
                $entryPrice = 30000,
                $size = 1.1,
                $value = 33000,
                $margin = 330,
                $leverage = 100,
                $liqPrice = 31000,
            )->build(),
            '$expectedPosition' => new Position(
                $positionSide,
                $symbol,
                $entryPrice,
                $size,
                $value,
                $liqPrice,
                $margin,
                $leverage,
            ),
        ];

        yield sprintf('have no position (%s %s, %s)', $symbol->value, $positionSide->title(), $category->value) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => (new PositionResponseBuilder($category))->build(),
            '$expectedPosition' => null,
        ];
    }

    /**
     * @dataProvider addStopTestCases
     */
    public function testAddStop(
        Symbol $symbol,
        AssetCategory $category,
        Side $positionSide,
        MockResponse $apiResponse,
        ?string $expectedExchangeOrderId
    ): void {
        // Arrange
        $expectedRequest = PlaceOrderRequest::stopConditionalOrderTriggeredByIndexPrice(
            $category,
            $symbol,
            $positionSide,
            $volume = 0.1,
            $price = 30000
        );

        $requestUrl = $this->getFullRequestUrl($expectedRequest);
        $this->httpClientStub->matchPost($requestUrl, $apiResponse);

        $position = new Position($positionSide, $symbol, 30000, 1.1, 33000, 31000, 330, 100);
        $ticker = TickerFactory::create($symbol, 29050);

        // Act
        $exchangeOrderId = $this->service->addStop($position, $ticker, $price, $volume);

        // Assert
        self::assertEquals($expectedExchangeOrderId, $exchangeOrderId);
    }

    private function addStopTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $category = AssetCategory::linear;
        $positionSide = Side::Sell;

        yield sprintf('add %s %s position stop (%s)', $symbol->value, $positionSide->title(), $category->value) => [
            $symbol, $category, $positionSide,
            '$apiResponse' => TradeResponseBuilder::ok($exchangeOrderId = uuid_create())->build(),
            '$expectedExchangeOrderId' => $exchangeOrderId,
        ];
    }

    /**
     * @todo | tests | make some kind of mixin to work with api
     */
    private function getFullRequestUrl(AbstractByBitApiRequest $request): string
    {
        return self::HOST . $request->url();
    }
}
