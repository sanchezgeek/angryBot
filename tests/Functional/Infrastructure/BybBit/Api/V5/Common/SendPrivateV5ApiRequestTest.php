<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Api\V5\Common;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\CancelOrderRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\GetCurrentOrdersRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Tests\Functional\Infrastructure\BybBit\Api\V5\ByBitV5ApiRequestTestAbstract;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mock\Response\ByBit\PositionResponses;
use App\Tests\Mock\Response\ByBit\TradeResponses;
use Symfony\Component\HttpFoundation\Request;

use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\API\V5\ByBitV5ApiClient
 */
final class SendPrivateV5ApiRequestTest extends ByBitV5ApiRequestTestAbstract
{
    use PositionSideAwareTest;

    /**
     * @dataProvider privateGetRequests
     */
    public function testSendPrivateGetRequest(AbstractByBitApiRequest $request): void
    {
        // Arrange
        $requestUrl = $this->getFullRequestUrl($request);
        $this->httpClientStub->matchGet($requestUrl, $request->data(), PositionResponses::positions());

        $expectedResult = $this->okRequestResult(PositionResponses::SAMPLE_POSITIONS_RESPONSE['result']);
        $expectedHeaders = $this->expectedPrivateHeaders($request);

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

    private function privateGetRequests(): array
    {
        return [
            [new GetPositionsRequest(AssetCategory::linear, Symbol::BTCUSDT)],
            [GetCurrentOrdersRequest::openOnly(AssetCategory::linear, Symbol::BTCUSDT)],
        ];
    }

    /**
     * @dataProvider privatePostRequests
     */
    public function testSendPrivatePostRequest(AbstractByBitApiRequest $request): void
    {
        // Arrange
        $requestUrl = $this->getFullRequestUrl($request);
        $this->httpClientStub->matchPost($requestUrl, TradeResponses::placeOrderOK());

        $expectedResult = $this->okRequestResult(TradeResponses::SAMPLE_PLACE_ORDER_RESPONSE['result']);
        $expectedHeaders = $this->expectedPrivateHeaders($request);

        // Act
        $result = $this->client->send($request);

        // Assert
        self::assertEquals($expectedResult, $result);
        self::assertCount(1, $this->httpClientStub->getRequestCalls());

        $requestCall = $this->httpClientStub->getRequestCalls()[0];
        self::assertSame(Request::METHOD_POST, $request->method());
        self::assertSame(Request::METHOD_POST, $requestCall->method);
        self::assertNull($requestCall->params);
        self::assertSame($request->data(), $requestCall->body);
        self::assertSame($requestUrl, $requestCall->url);
        self::assertEqualsCanonicalizing($expectedHeaders, $requestCall->headers);
    }

    private function privatePostRequests(): array
    {
        return [
            [
                PlaceOrderRequest::stopConditionalOrderTriggeredByIndexPrice(
                    AssetCategory::linear,
                    Symbol::BTCUSDT,
                    Side::Sell,
                    0.01,
                    30000.1
                )
            ],
            [
                CancelOrderRequest::byOrderId(AssetCategory::linear, Symbol::BTCUSDT, uuid_create())
            ],
        ];
    }
}
