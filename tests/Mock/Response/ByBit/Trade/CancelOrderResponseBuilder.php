<?php

declare(strict_types=1);

namespace App\Tests\Mock\Response\ByBit\Trade;

use App\Infrastructure\ByBit\API\Common\Result\ApiErrorInterface;
use App\Tests\Mock\Response\MockResponseFactoryTrait;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_replace_recursive;

final class CancelOrderResponseBuilder
{
    use MockResponseFactoryTrait;

    public const SAMPLE_CANCEL_ORDER_SUCCESS_RESPONSE = [
        'retCode' => 0,
        'retMsg' => 'OK',
        'result' => [
            'orderId' => 'some-order-id',
            'orderLinkId' => 'linear-004'
        ],
        'retExtInfo' => [],
        'time' => 1672217377164
    ];

    public const SAMPLE_CANCEL_ORDER_FAIL_RESPONSE = [
        'retCode' => 100500,
        'retMsg' => 'Not OK',
        'retExtInfo' => [],
        'time' => 1672217377164
    ];

    private function __construct(private readonly ?ApiErrorInterface $error, private readonly ?string $orderId)
    {
    }

    public static function ok(string $orderId): self
    {
        return new self(null, $orderId);
    }

    public static function error(ApiErrorInterface $error): self
    {
        return new self($error, null);
    }

    public function build(): MockResponse
    {
        if ($this->error) {
            $body = array_replace_recursive(self::SAMPLE_CANCEL_ORDER_FAIL_RESPONSE, [
                'retCode' => $this->error->code(),
                'retMsg' => $this->error->msg(),
            ]);
        } else {
            $body = array_replace_recursive(self::SAMPLE_CANCEL_ORDER_SUCCESS_RESPONSE, [
                'result' => ['orderId' => $this->orderId]
            ]);
        }


        return self::make($body);
    }
}
