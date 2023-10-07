<?php

namespace App\Tests\Mock\Response;

use App\Tests\Mixin\JsonTrait;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

trait MockResponseFactoryTrait
{
    use JsonTrait;

    /**
     * @todo | apiV5 | research RESPONSE code (always 200?)
     */
    protected static function make(array $jsonBody, int $status = Response::HTTP_OK): MockResponse
    {
        return new MockResponse(self::jsonEncode($jsonBody), ['http_code' => $status]);
    }
}
