<?php

declare(strict_types=1);

namespace App\Tests\Mock\Response\ByBitV5Api;

use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Tests\Mock\Response\MockResponseFactoryTrait;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_replace_recursive;

final class ErrorResponseFactory
{
    use MockResponseFactoryTrait;

    public const SAMPLE_FAILED_RESPONSE = [
        'retCode' => 0,
        'retMsg' => '',
        'retExtInfo' => [],
        'time' => 1672211918471
    ];

    public static function error(int $code, string $msg): MockResponse
    {
        $body = array_replace_recursive(self::SAMPLE_FAILED_RESPONSE, [
            'retCode' => $code,
            'retMsg' => $msg,
        ]);

        return self::make($body);
    }

    public static function knownError(ApiV5Errors $err, string $msg = null): MockResponse
    {
        $body = array_replace_recursive(self::SAMPLE_FAILED_RESPONSE, [
            'retCode' => $err->code(),
            'retMsg' => $msg ?: $err->desc(),
        ]);

        return self::make($body);
    }

    public static function unknownError(int $code, string $msg): MockResponse
    {
        $body = array_replace_recursive(self::SAMPLE_FAILED_RESPONSE, [
            'retCode' => $code,
            'retMsg' => $msg,
        ]);

        return self::make($body);
    }
}
