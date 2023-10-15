<?php

namespace App\Infrastructure\ByBit\API\Common\Result;

interface ApiErrorInterface
{
    public function code(): int;
    public function desc(): string;
    public function msg(): string;
}
