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
use App\Infrastructure\ByBit\ByBitPositionService;
use App\Tests\Mock\Response\ByBit\PositionResponseBuilder;
use App\Tests\Stub\Request\SymfonyHttpClientStub;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use Symfony\Component\HttpClient\Response\MockResponse;

use function sprintf;

/**
 * @covers \App\Infrastructure\ByBit\ByBitPositionService
 */
final class ByBitPositionServiceTest extends KernelTestCase
{
    private const HOST = 'https://api-testnet.bybit.com';
    private const API_KEY = 'bybit-api-key';
    private const API_SECRET = 'bybit-api-secret';

    private SymfonyHttpClientStub $httpClientStub;

    private ByBitPositionService $service;

    protected function setUp(): void
    {
        $clockMock = $this->createMock(ClockInterface::class);
        $clockMock->method('now')->willReturn(new DateTimeImmutable());

        $this->httpClientStub = new SymfonyHttpClientStub(self::HOST);

        $this->service = new ByBitPositionService(
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
     * @todo | tests | make some kind of mixin to work with api
     */
    private function getFullRequestUrl(AbstractByBitApiRequest $request): string
    {
        return self::HOST . $request->url();
    }
}
