<?php

declare(strict_types=1);

namespace App\Tests\Mock\Response\ByBit;

use App\Tests\Mock\Response\MockResponseFactoryTrait;
use App\Tests\Mock\Response\ResponseBuilderInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TradeResponseBuilder implements ResponseBuilderInterface
{
    use MockResponseFactoryTrait;

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

    private int $statusCode = 200;

    private string $orderId;

    private function __construct(string $orderId)
    {
        $this->orderId = $orderId;
    }

    public static function ok(string $orderId): self
    {
        return new self($orderId);
    }

    public function build(): MockResponse
    {
        $body = self::SAMPLE_PLACE_ORDER_RESPONSE;

        $body['result']['orderId'] = $this->orderId;

        return self::make($this->statusCode, $body);
    }
}
