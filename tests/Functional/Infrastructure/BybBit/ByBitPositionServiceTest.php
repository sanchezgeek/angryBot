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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function date_create_immutable;

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
        $clockMock->method('now')->willReturn(date_create_immutable());

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

    public function testGetPosition(): void
    {
        $symbol = Symbol::BTCUSDT;
        $category = AssetCategory::linear;
        $positionSide = Side::Sell;

        $expectedRequest = new GetPositionsRequest($category, $symbol);
        $requestUrl = $this->getFullRequestUrl($expectedRequest);

        $expectedPosition = new Position(
            $positionSide,
            $symbol,
            $entryPrice = 30000,
            $size = 1.1,
            $value = 33000,
            $liqPrice = 31000,
            $margin = 330,
            $leverage = 100,
        );

        $response = (new PositionResponseBuilder($category))
            ->addPosition($symbol, $positionSide, $entryPrice, $size, $value, $margin, $leverage, $liqPrice)
            ->build();

        $this->httpClientStub->matchGet($requestUrl, $expectedRequest->data(), $response);

        // Act
        $position = $this->service->getPosition($symbol, $positionSide);

        // Assert
        self::assertEquals($expectedPosition, $position);
    }

    /**
     * @todo | tests | make some kind of mixin to work with api
     */
    private function getFullRequestUrl(AbstractByBitApiRequest $request): string
    {
        return self::HOST . $request->url();
    }
}
