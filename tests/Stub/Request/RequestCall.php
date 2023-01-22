<?php

declare(strict_types=1);

namespace App\Tests\Stub\Request;

final readonly class RequestCall
{
    public function __construct(
        public string $method,
        public string $url,
        public array $params,
        public ?array $body,
    ) {
    }

    public static function get(string $url, array $params): self
    {
        return new self('GET', $url, $params, null);
    }

    public static function post(string $url, array $params, array $body): self
    {
        return new self('POST', $url, $params, $body);
    }
}
