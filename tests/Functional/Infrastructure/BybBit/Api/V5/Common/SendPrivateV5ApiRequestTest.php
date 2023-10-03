<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Api\V5\Common;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Tests\Functional\Infrastructure\BybBit\Api\V5\ByBitV5ApiRequestTestAbstract;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mock\Response\ByBit\PositionResponses;
use App\Tests\Mock\Response\ByBit\TradeResponses;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \App\Infrastructure\ByBit\API\V5\ByBitV5ApiClient
 */
final class SendPrivateV5ApiRequestTest extends ByBitV5ApiRequestTestAbstract
{
    use PositionSideAwareTest;

    public function testSendPrivateGetRequest(): void
    {
        // Arrange
        $request = new GetPositionsRequest(AssetCategory::linear, Symbol::BTCUSDT);

        $requestUrl = $this->getFullRequestUrl($request);
        $this->httpClientStub->matchGet($requestUrl, $request->data(), PositionResponses::positions());

        $expectedResponseBody = PositionResponses::SAMPLE_POSITIONS_RESPONSE;
        $expectedPrivateHeaders = $this->expectedPrivateHeaders($request);

        // Act
        $actualResponse = $this->client->send($request);

        // Assert
        self::assertSame($expectedResponseBody, $actualResponse);
        self::assertCount(1, $this->httpClientStub->getRequestCalls());

        $requestCall = $this->httpClientStub->getRequestCalls()[0];
        self::assertSame(Request::METHOD_GET, $request->method());
        self::assertSame(Request::METHOD_GET, $requestCall->method);
        self::assertSame($request->data(), $requestCall->params);
        self::assertSame($requestUrl, $requestCall->url);
        self::assertEqualsCanonicalizing($expectedPrivateHeaders, $requestCall->headers);
    }

    public function testSendPrivatePostRequest(): void
    {
        // Arrange
        $request = PlaceOrderRequest::stopConditionalOrderTriggeredByIndexPrice(
            AssetCategory::linear,
            Symbol::BTCUSDT,
            Side::Sell,
            0.01,
            30000.1
        );

        $requestUrl = $this->getFullRequestUrl($request);
        $this->httpClientStub->matchPost($requestUrl, TradeResponses::placeOrderOK());

        $expectedResponseBody = TradeResponses::SAMPLE_PLACE_ORDER_RESPONSE;
        $expectedPrivateHeaders = $this->expectedPrivateHeaders($request);

        // Act
        $actualResponse = $this->client->send($request);

        // Assert
        self::assertSame($expectedResponseBody, $actualResponse);
        self::assertCount(1, $this->httpClientStub->getRequestCalls());

        $requestCall = $this->httpClientStub->getRequestCalls()[0];
        self::assertSame(Request::METHOD_POST, $request->method());
        self::assertSame(Request::METHOD_POST, $requestCall->method);
        self::assertNull($requestCall->params);
        self::assertSame($request->data(), $requestCall->body);
        self::assertSame($requestUrl, $requestCall->url);
        self::assertEqualsCanonicalizing($expectedPrivateHeaders, $requestCall->headers);
    }
}
