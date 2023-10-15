<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API;

use App\Infrastructure\ByBit\API\Exception\AbstractByBitApiException;
use App\Infrastructure\ByBit\API\Result\ByBitApiCallResult;
use RuntimeException;

interface ByBitApiClientInterface
{
    /**
     * @throws RuntimeException             While do http call and process result according to API-contract
     * @throws AbstractByBitApiException    When get response with retCode != 0
     */
    public function send(AbstractByBitApiRequest $request): ByBitApiCallResult;
}
