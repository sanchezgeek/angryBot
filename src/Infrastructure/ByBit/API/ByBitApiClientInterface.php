<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API;

interface ByBitApiClientInterface
{
    public function send(AbstractByBitApiRequest $request): array;
}
