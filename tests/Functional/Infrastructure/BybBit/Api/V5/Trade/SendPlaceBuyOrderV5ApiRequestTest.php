<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Api\V5\Trade;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Tests\Functional\Infrastructure\BybBit\Api\V5\ByBitV5ApiRequestTestAbstract;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mock\Response\ByBitV5Api\TradeResponses;
use Symfony\Component\HttpFoundation\Request;

use function sprintf;

/**
 * @covers \App\Infrastructure\ByBit\API\V5\ByBitV5ApiClient
 */
final class SendPlaceBuyOrderV5ApiRequestTest extends ByBitV5ApiRequestTestAbstract
{
    use PositionSideAwareTest;

    /**
     * @dataProvider placeOrderRequestProvider
     */
    public function testSendPlaceBuyOrderRequest(PlaceOrderRequest $request): void
    {
        // Arrange
        $requestUrl = $this->getFullRequestUrl($request);
        $mockRequestKey = $this->httpClientStub->matchPost($requestUrl, TradeResponses::placeOrderOK(), $request->data());

        $expectedResult = $this->okRequestResult(TradeResponses::SAMPLE_PLACE_ORDER_RESPONSE['result']);
        $expectedPrivateHeaders = $this->expectedPrivateHeaders($request);

        // Act
        $result = $this->client->send($request);

        // Assert
        self::assertEquals($expectedResult, $result);
        self::assertCount(1, $this->httpClientStub->getRequestCalls());

        $requestCall = $this->httpClientStub->getRequestCalls()[$mockRequestKey];
        self::assertSame(Request::METHOD_POST, $request->method());
        self::assertSame(Request::METHOD_POST, $requestCall->method);
        self::assertNull($requestCall->params);
        self::assertSame($request->data(), $requestCall->body);
        self::assertSame($requestUrl, $requestCall->url);
        self::assertEqualsCanonicalizing($expectedPrivateHeaders, $requestCall->headers);
    }

    private function placeOrderRequestProvider(): iterable
    {
        foreach ($this->positionSideProvider() as [$side]) {
            yield sprintf('%s-position condition SL', $side->title()) => [
                PlaceOrderRequest::stopConditionalOrder(
                    AssetCategory::linear,
                    Symbol::BTCUSDT,
                    $side,
                    0.01,
                    30000.1,
                    TriggerBy::IndexPrice
                )
            ];

            yield sprintf('%s-position immediately BuyOrder', $side->title()) => [
                PlaceOrderRequest::marketBuy(
                    AssetCategory::linear,
                    Symbol::BTCUSDT,
                    $side,
                    0.01,
                )
            ];
        }
    }
}
