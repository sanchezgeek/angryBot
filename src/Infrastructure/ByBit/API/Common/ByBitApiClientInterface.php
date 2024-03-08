<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\Common;

use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\PermissionDeniedException;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use RuntimeException;

interface ByBitApiClientInterface
{
    /**
     * @throws RuntimeException                 While do http call and process result according to the API-contract
     * @throws UnknownByBitApiErrorException    When get response with unknown retCode
     * @throws ApiRateLimitReached
     * @throws PermissionDeniedException
     */
    public function send(AbstractByBitApiRequest $request): ByBitApiCallResult;
}
