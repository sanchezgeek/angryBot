<?php

declare(strict_types=1);

namespace App\Tests\Mock\Response\ByBitV5Api;

use App\Tests\Mock\Response\AbstractMockResponseFactory;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TradeResponses extends AbstractMockResponseFactory
{
    public const SAMPLE_PLACE_ORDER_RESPONSE = [
        'retCode' => 0,
        'retMsg' => 'OK',
        'result' => [
            'orderId' => '1321003749386327552',
            'orderLinkId' => 'spot-test-postonly'
        ],
        'retExtInfo' => [],
        'time' => 1672211918471
    ];

    public static function placeOrderOK(array $body = self::SAMPLE_PLACE_ORDER_RESPONSE): MockResponse
    {
        return self::make(200, $body);
    }
}
