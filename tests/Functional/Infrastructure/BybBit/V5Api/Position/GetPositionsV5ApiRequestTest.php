<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\V5Api\Position;

use App\Infrastructure\ByBit\V5Api\Request\Position\GetPositionsRequest;
use App\Tests\Functional\Infrastructure\BybBit\V5Api\ByBitV5ApiRequestTestAbstract;
use App\Tests\Mock\Response\ByBit\ByBitResponses;

/**
 * @covers \App\Infrastructure\ByBit\V5Api\ByBitV5ApiClient
 */
final class GetPositionsV5ApiRequestTest extends ByBitV5ApiRequestTestAbstract
{
    public function testSendGetPositionsRequest(): void
    {
        // Arrange
        $request = new GetPositionsRequest('linear', 'BTCUSDT');
        $requestUrl = $this->getFullRequestUrl($request);
        $this->httpClientStub->matchGet($requestUrl, $request->data(), ByBitResponses::positions());

        $expectedResponseBody = ByBitResponses::SAMPLE_POSITIONS_RESPONSE;
        $expectedPrivateHeaders = $this->expectedPrivateHeaders($request);

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
