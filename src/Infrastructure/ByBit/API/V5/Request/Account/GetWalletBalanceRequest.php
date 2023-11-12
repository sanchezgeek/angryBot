<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Account;

use App\Domain\Coin\Coin;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use Symfony\Component\HttpFoundation\Request;

final readonly class GetWalletBalanceRequest extends AbstractByBitApiRequest
{
    public function method(): string
    {
        return Request::METHOD_GET;
    }

    public function url(): string
    {
        return '/v5/account/wallet-balance';
    }

    public function data(): array
    {
        return ['accountType' => $this->accountType->value, 'coin' => $this->coin->value];
    }

    public function __construct(private AccountType $accountType, private Coin $coin)
    {
    }
}
