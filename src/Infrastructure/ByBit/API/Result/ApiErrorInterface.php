<?php

namespace App\Infrastructure\ByBit\API\Result;

interface ApiErrorInterface
{
    public function code(): int;
    public function desc(): string;
    // @todo + received message from api?
}
