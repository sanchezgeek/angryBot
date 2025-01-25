<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Account;

use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see https://bybit-exchange.github.io/docs/v5/user/create-subuid-apikey
 */
final readonly class CreateSubAccountApiKeyRequest extends AbstractByBitApiRequest
{
    public const Wallet_AccountTransfer = 'AccountTransfer';
    public const ContractTrade_Order = 'Order';
    public const ContractTrade_Position = 'Position';

    public const URL = '/v5/user/create-sub-api';

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
            'subuid' => $this->accountUid,
            'note' => $this->note,
            'readOnly' => 0,
            'permissions' => $this->permissions
        ];
    }

    public function __construct(
        private int $accountUid,
        private string $note = 'just-key',
        private array $permissions = [
            'WALLET' => [self::Wallet_AccountTransfer],
            'ContractTrade' => [self::ContractTrade_Order, self::ContractTrade_Position],
        ]
    ) {
    }
}
