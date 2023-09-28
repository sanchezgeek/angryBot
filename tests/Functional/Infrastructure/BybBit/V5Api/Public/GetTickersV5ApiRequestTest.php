<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\V5Api\Public;

use App\Infrastructure\ByBit\V5Api\Request\GetTickerRequest;
use App\Tests\Functional\Infrastructure\BybBit\V5Api\ByBitV5ApiRequestTestAbstract;
use App\Tests\Mock\Response\ByBit\ByBitResponses;

final class GetTickersV5ApiRequestTest extends ByBitV5ApiRequestTestAbstract
{
    public function testSendTickersRequest(): void
    {
        // Arrange
        $request = new GetTickerRequest('linear', 'BTCUSDT');
        $requestUrl = $this->getFullRequestUrl($request);
        $this->httpClientStub->matchGet($requestUrl, $request->data(), ByBitResponses::tickers());

        $expectedResponseBody = ByBitResponses::SAMPLE_TICKERS_RESPONSE;
        $expectedPrivateHeaders = $this->expectedPublicHeaders();

        // Act
        $actualResponse = $this->client->send($request);

        // Assert
        self::assertSame($expectedResponseBody, $actualResponse);
        self::assertCount(1, $this->httpClientStub->getRequestCalls());

        $requestCall = $this->httpClientStub->getRequestCalls()[0];
        self::assertSame($request->method(), $requestCall->method);
        self::assertSame($requestUrl, $requestCall->url);
        self::assertSame($expectedPrivateHeaders, $requestCall->headers);
    }
}
