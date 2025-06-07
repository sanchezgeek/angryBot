<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Api\V5\Market;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest;
use App\Tests\Functional\Infrastructure\BybBit\Api\V5\ByBitV5ApiRequestTestAbstract;
use App\Tests\Mock\Response\ByBitV5Api\MarketResponses;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \App\Infrastructure\ByBit\API\V5\ByBitV5ApiClient
 */
final class SendGetTickersV5ApiRequestTest extends ByBitV5ApiRequestTestAbstract
{
    public function testSendGetTickersRequest(): void
    {
        // Arrange
        $request = new GetTickersRequest(AssetCategory::linear, SymbolEnum::BTCUSDT);
        $requestUrl = $this->getFullRequestUrl($request);
        $mockRequestKey = $this->httpClientStub->matchGet($requestUrl, $request->data(), MarketResponses::tickers());

        $expectedResult = $this->okRequestResult(MarketResponses::SAMPLE_TICKERS_RESPONSE['result']);
        $expectedPrivateHeaders = $this->expectedPublicHeaders($request);

        // Act
        $actualResult = $this->client->send($request);

        // Assert
        self::assertEquals($expectedResult, $actualResult);
        self::assertCount(1, $this->httpClientStub->getRequestCalls());

        $requestCall = $this->httpClientStub->getRequestCalls()[$mockRequestKey];
        self::assertSame(Request::METHOD_GET, $request->method());
        self::assertSame(Request::METHOD_GET, $requestCall->method);
        self::assertSame($request->data(), $requestCall->params);
        self::assertSame($requestUrl, $requestCall->url);
        self::assertEqualsCanonicalizing($expectedPrivateHeaders, $requestCall->headers);
    }
}
