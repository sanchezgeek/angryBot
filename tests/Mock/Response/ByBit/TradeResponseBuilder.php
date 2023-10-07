<?php

declare(strict_types=1);

namespace App\Tests\Mock\Response\ByBit;

use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Error;
use App\Tests\Mock\Response\MockResponseFactoryTrait;
use App\Tests\Mock\Response\ResponseBuilderInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_replace_recursive;

final class TradeResponseBuilder implements ResponseBuilderInterface
{
    use MockResponseFactoryTrait;

    public const SAMPLE_PLACE_ORDER_SUCCESS_RESPONSE = [
        'retCode' => 0,
        'retMsg' => 'OK',
        'result' => [
            'orderId' => '1321003749386327552',
            'orderLinkId' => 'spot-test-postonly'
        ],
        'retExtInfo' => [],
        'time' => 1672211918471
    ];

    public const SAMPLE_PLACE_ORDER_FAILED_RESPONSE = [
        'retCode' => 1000,
        'retMsg' => 'Api rate limit reached',
        'retExtInfo' => [],
        'time' => 1672211918471
    ];

    private function __construct(private readonly ?ApiV5Error $error, private readonly ?string $orderId)
    {
    }

    public static function ok(string $orderId): self
    {
        return new self(null, $orderId);
    }

    public static function error(ApiV5Error $error): self
    {
        return new self($error, null);
    }

    public function build(): MockResponse
    {
        if ($this->error) {
            $body = array_replace_recursive(self::SAMPLE_PLACE_ORDER_FAILED_RESPONSE, [
                'retCode' => $this->error->code(),
                'retMsg' => $this->error->desc(),
            ]);
        } else {
            $body = array_replace_recursive(self::SAMPLE_PLACE_ORDER_SUCCESS_RESPONSE, [
                'result' => ['orderId' => $this->orderId]
            ]);
        }

        return self::make($body);
    }
}
