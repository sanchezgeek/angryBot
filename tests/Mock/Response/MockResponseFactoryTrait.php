<?php

namespace App\Tests\Mock\Response;

use App\Tests\Mixin\JsonTrait;
use Symfony\Component\HttpClient\Response\MockResponse;

trait MockResponseFactoryTrait
{
    use JsonTrait;

    protected static function make(int $status, array $jsonBody): MockResponse
    {
        return new MockResponse(self::jsonEncode($jsonBody), ['http_code' => $status]);
    }
}
