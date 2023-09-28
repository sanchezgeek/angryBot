<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit;

abstract readonly class AbstractByBitApiRequest
{
    abstract public function url(): string;
    abstract public function method(): string;
    abstract public function data(): array;
}
