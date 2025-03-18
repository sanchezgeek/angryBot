<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Account;

use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see https://bybit-exchange.github.io/docs/v5/user/apikey-info
 */
final readonly class ModifySubAccApiKeyRequest extends AbstractByBitApiRequest
{
    public const URL = '/v5/user/update-sub-api';

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
        $data = [
            'readOnly' => (int)$this->readOnly
        ];

        if ($this->subAccApiKey) {
            $data['apikey'] = $this->subAccApiKey;
        }

        return $data;
    }

    public static function justRefresh(?string $subAccApiKey = null): self
    {
        return new self(subAccApiKey: $subAccApiKey, readOnly: false);
    }

    private function __construct(
        private ?string $subAccApiKey,
        private bool $readOnly
    ) {

    }
}
