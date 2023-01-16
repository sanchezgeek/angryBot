<?php

declare(strict_types=1);

namespace App\Api\Response;

final class SuccessResponseDto
{
    public string $status = 'Success';

    public array $payload = [];

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }
}
