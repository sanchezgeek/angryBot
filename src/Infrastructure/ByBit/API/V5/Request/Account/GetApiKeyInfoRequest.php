<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Account;

use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see https://bybit-exchange.github.io/docs/v5/user/apikey-info
 */
final readonly class GetApiKeyInfoRequest extends AbstractByBitApiRequest
{
    public const URL = '/v5/user/query-api';

    public function method(): string
    {
        return Request::METHOD_GET;
    }

    public function url(): string
    {
        return self::URL;
    }

    public function data(): array
    {
        return [];
    }
}
