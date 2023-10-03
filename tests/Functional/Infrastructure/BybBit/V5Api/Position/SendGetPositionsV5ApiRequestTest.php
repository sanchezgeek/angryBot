<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\V5Api\Position;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Tests\Functional\Infrastructure\BybBit\V5Api\ByBitV5ApiRequestTestAbstract;
use App\Tests\Mock\Response\ByBit\PositionResponses;

/**
 * @covers \App\Infrastructure\ByBit\API\V5\ByBitV5ApiClient
 */
final class SendGetPositionsV5ApiRequestTest extends ByBitV5ApiRequestTestAbstract
{
    public function testSendGetPositionsRequest(): void
    {
        // Arrange
        $request = new GetPositionsRequest(AssetCategory::linear, Symbol::BTCUSDT);
        $requestUrl = $this->getFullRequestUrl($request);
        $this->httpClientStub->matchGet($requestUrl, $request->data(), PositionResponses::positions());

        $expectedResponseBody = PositionResponses::SAMPLE_POSITIONS_RESPONSE;
        $expectedPrivateHeaders = $this->expectedHeaders($request);

        // Act
        $actualResponse = $this->client->send($request);

        // Assert
        self::assertSame($expectedResponseBody, $actualResponse);
        self::assertCount(1, $this->httpClientStub->getRequestCalls());

        $requestCall = $this->httpClientStub->getRequestCalls()[0];
        self::assertSame($request->method(), $requestCall->method);
        self::assertSame($request->data(), $requestCall->params);
        self::assertSame($requestUrl, $requestCall->url);
        self::assertEqualsCanonicalizing($expectedPrivateHeaders, $requestCall->headers);
    }
}
