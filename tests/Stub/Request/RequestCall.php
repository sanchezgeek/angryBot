<?php

declare(strict_types=1);

namespace App\Tests\Stub\Request;

final readonly class RequestCall
{
    public function __construct(
        public string $method,
        public string $url,
        public ?array $params = null,
        public ?array $body = null,
        public ?array $headers = null,
    ) {
    }
}
