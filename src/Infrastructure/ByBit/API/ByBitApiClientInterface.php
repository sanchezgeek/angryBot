<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API;

use App\Infrastructure\ByBit\API\Result\ByBitApiCallResult;

interface ByBitApiClientInterface
{
    public function send(AbstractByBitApiRequest $request): ByBitApiCallResult;
}
