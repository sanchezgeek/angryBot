<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Api\V5\Position;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Tests\Functional\Infrastructure\BybBit\Api\V5\ByBitV5ApiRequestTestAbstract;
use App\Tests\Mock\Response\ByBitV5Api\PositionResponses;
use Symfony\Component\HttpFoundation\Request;

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
        $mockRequestKey = $this->httpClientStub->matchGet($requestUrl, $request->data(), PositionResponses::positions());

        $expectedResult = $this->okRequestResult(PositionResponses::SAMPLE_POSITIONS_RESPONSE['result']);
        $expectedPrivateHeaders = $this->expectedPrivateHeaders($request);

        // Act
        $result = $this->client->send($request);

        // Assert
        self::assertEquals($expectedResult, $result);
        self::assertCount(1, $this->httpClientStub->getRequestCalls());

        $requestCall = $this->httpClientStub->getRequestCalls()[$mockRequestKey];
        self::assertSame(Request::METHOD_GET, $request->method());
        self::assertSame(Request::METHOD_GET, $requestCall->method);
        self::assertSame($request->data(), $requestCall->params);
        self::assertSame($requestUrl, $requestCall->url);
        self::assertEqualsCanonicalizing($expectedPrivateHeaders, $requestCall->headers);
    }
}
