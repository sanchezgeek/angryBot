<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\V5Api\Trade;

use App\Bot\Domain\ValueObject\Order\ExecutionOrderType;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\V5Api\Request\Trade\Enum\TimeInForceParam;
use App\Infrastructure\ByBit\V5Api\Request\Trade\Enum\TriggerByParam;
use App\Infrastructure\ByBit\V5Api\Request\Trade\PlaceOrderRequest;
use App\Tests\Functional\Infrastructure\BybBit\V5Api\ByBitV5ApiRequestTestAbstract;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mock\Response\ByBit\TradeResponses;

/**
 * @covers \App\Infrastructure\ByBit\V5Api\ByBitV5ApiClient
 */
final class SendPlaceBuyOrderV5ApiRequestTest extends ByBitV5ApiRequestTestAbstract
{
    use PositionSideAwareTest;

    /**
     * @dataProvider positionSideProvider
     */
    public function testSendPlaceBuyOrderRequest(Side $side): void
    {
        // Arrange
        $request = new PlaceOrderRequest(
            'linear',
            'BTCUSDT',
            $side,
            ExecutionOrderType::Market,
            TriggerByParam::IndexPrice,
            TimeInForceParam::GTC,
            false,
            false,
            0.01,
            30000.1
        );

        $requestUrl = $this->getFullRequestUrl($request);
        $this->httpClientStub->matchPost($requestUrl, TradeResponses::placeOrderOK());

        $expectedResponseBody = TradeResponses::SAMPLE_PLACE_ORDER_RESPONSE;
        $expectedPrivateHeaders = $this->expectedHeaders($request);

        // Act
        $actualResponse = $this->client->send($request);

        // Assert
        self::assertSame($expectedResponseBody, $actualResponse);
        self::assertCount(1, $this->httpClientStub->getRequestCalls());

        $requestCall = $this->httpClientStub->getRequestCalls()[0];
        self::assertSame($request->method(), $requestCall->method);
        self::assertNull($requestCall->params);
        self::assertSame($request->data(), $requestCall->body);
        self::assertSame($requestUrl, $requestCall->url);
        self::assertEqualsCanonicalizing($expectedPrivateHeaders, $requestCall->headers);
    }
}
