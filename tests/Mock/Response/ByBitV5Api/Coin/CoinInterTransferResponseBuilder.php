<?php

declare(strict_types=1);

namespace App\Tests\Mock\Response\ByBitV5Api\Coin;

use App\Tests\Mock\Response\MockResponseFactoryTrait;
use App\Tests\Mock\Response\ResponseBuilderInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CoinInterTransferResponseBuilder implements ResponseBuilderInterface
{
    use MockResponseFactoryTrait;

    public const ROOT_BODY_ARRAY = [
        "retCode" => 0,
        "retMsg" => "OK",
        "result" => [
            'transferId' => '123456',
        ],
        "retExtInfo" => [],
        "time" => 1672376496682
    ];

    private function __construct(private readonly string $transferId)
    {
    }

    public static function ok(string $transferId): self
    {
        return new self($transferId);
    }

    public function build(): MockResponse
    {
        $body = self::ROOT_BODY_ARRAY;

        $body['result']['transferId'] = $this->transferId;

        return self::make($body);
    }
}
