<?php

namespace App\Infrastructure\ByBit\API\Common\Result;

interface ApiErrorInterface
{
    public function code(): int;
    public function msg(): string;

//    public function desc(): string;
}
