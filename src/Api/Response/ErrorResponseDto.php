<?php

declare(strict_types=1);

namespace App\Api\Response;

final class ErrorResponseDto
{
    public array $errors;

    public function __construct(array $errors)
    {
        $this->errors = $errors;
    }
}
