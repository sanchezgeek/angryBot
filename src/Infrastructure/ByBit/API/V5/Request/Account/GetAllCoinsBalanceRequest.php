<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Account;

use App\Domain\Coin\Coin;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see https://bybit-exchange.github.io/docs/v5/account/wallet-balance
 */
final readonly class GetAllCoinsBalanceRequest extends AbstractByBitApiRequest
{
    public const URL = '/v5/asset/transfer/query-account-coins-balance';

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
        return ['accountType' => $this->accountType->value, 'coin' => $this->coin->value];
    }

    public function __construct(private AccountType $accountType, private Coin $coin)
    {
    }
}
