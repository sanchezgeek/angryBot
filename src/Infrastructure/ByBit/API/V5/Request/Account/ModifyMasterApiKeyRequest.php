<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Account;

use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see https://bybit-exchange.github.io/docs/v5/user/apikey-info
 */
final readonly class ModifyMasterApiKeyRequest extends AbstractByBitApiRequest
{
    public const URL = '/v5/user/update-api';

    public function method(): string
    {
        return Request::METHOD_POST;
    }

    public function url(): string
    {
        return self::URL;
    }

    public function data(): array
    {
        return [
            'readOnly' => (int) $this->readOnly
        ];
    }

    public static function justRefresh(): self
    {
        return new self(false);
    }

    private function __construct(
        private bool $readOnly
    ) {

    }
}
