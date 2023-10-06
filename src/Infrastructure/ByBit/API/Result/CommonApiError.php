<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\Result;

final readonly class CommonApiError implements ApiErrorInterface
{
    public function __construct(private int $code, private string $desc)
    {

    }

    public function code(): int
    {
        return $this->code;
    }

    public function desc(): string
    {
        return $this->desc;
    }
}
