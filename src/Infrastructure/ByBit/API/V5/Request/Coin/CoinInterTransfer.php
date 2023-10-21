<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Coin;

use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Enum\Account\Coin;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use function assert;
use function sprintf;

/**
 * @see https://bybit-exchange.github.io/docs/v5/asset/create-inter-transfer
 */
final readonly class CoinInterTransfer extends AbstractByBitApiRequest
{
    public const URL = '/v5/asset/transfer/inter-transfer';

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
            'coin' => $this->coin->value,
            'amount' => (string)$this->amount,
            'fromAccountType' => $this->fromAccountType->value,
            'toAccountType' => $this->toAccountType->value,
            'transferId' => $this->transferId,
        ];
    }

    public function __construct(
        private Coin        $coin,
        private AccountType $fromAccountType,
        private AccountType $toAccountType,
        private float       $amount,
        private string      $transferId,
    ) {
        assert($this->transferId, new InvalidArgumentException(
            sprintf('%s: $transferId must be non-empty string (`%s` provided)', __CLASS__, $this->transferId)
        ));

        assert($this->amount > 0, new InvalidArgumentException(
            sprintf('%s: $amount must be greater than zero (`%f` provided)', __CLASS__, $this->amount)
        ));
    }
}
