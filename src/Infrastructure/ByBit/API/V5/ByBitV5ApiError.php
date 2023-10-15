<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5;

use App\Infrastructure\ByBit\API\Common\Result\ApiErrorInterface;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;

final readonly class ByBitV5ApiError implements ApiErrorInterface
{
    public function __construct(
        private ApiV5Errors $error,
        private string $message,
    ) {
    }

    public function code(): int
    {
        return $this->error->code();
    }

    public function desc(): string
    {
        return $this->error->name;
    }

    public function msg(): string
    {
        return $this->message;
    }
}
