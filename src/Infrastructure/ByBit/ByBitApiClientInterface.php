<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit;

interface ByBitApiClientInterface
{
    public function send(AbstractByBitApiRequest $request): array;
}
