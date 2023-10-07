<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Api\V5\Common;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest;
use App\Tests\Functional\Infrastructure\BybBit\Api\V5\ByBitV5ApiRequestTestAbstract;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mock\Response\ByBit\MarketResponses;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \App\Infrastructure\ByBit\API\V5\ByBitV5ApiClient
 */
final class SendPublicV5ApiRequestTest extends ByBitV5ApiRequestTestAbstract
{
    use PositionSideAwareTest;

    public function testSendPublicGetRequest(): void
    {
        // Arrange
        $request = new GetTickersRequest(AssetCategory::linear, Symbol::BTCUSDT);
        $requestUrl = $this->getFullRequestUrl($request);
        $this->httpClientStub->matchGet($requestUrl, $request->data(), MarketResponses::tickers());

        $expectedResult = $this->okRequestResult(MarketResponses::SAMPLE_TICKERS_RESPONSE['result']);
        $expectedHeaders = $this->expectedPublicHeaders($request);

        // Act
        $result = $this->client->send($request);

        // Assert
        self::assertEquals($expectedResult, $result);
        self::assertCount(1, $this->httpClientStub->getRequestCalls());

        $requestCall = $this->httpClientStub->getRequestCalls()[0];
        self::assertSame(Request::METHOD_GET, $request->method());
        self::assertSame(Request::METHOD_GET, $requestCall->method);
        self::assertSame($request->data(), $requestCall->params);
        self::assertSame($requestUrl, $requestCall->url);
        self::assertEqualsCanonicalizing($expectedHeaders, $requestCall->headers);
    }
}
