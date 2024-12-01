<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Asset\Transfer;

use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function sprintf;

/**
 * @see https://bybit-exchange.github.io/docs/v5/asset/create-inter-transfer
 */
final readonly class CoinInterTransferRequest extends AbstractByBitApiRequest
{
    public const URL = '/v5/asset/transfer/inter-transfer';

    private Coin $coin;
    private float $amount;

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

    /**
     * @param string|null $transferId Because of apiV5 requires `transferId` option, use NULL for check in tests
     */
    private function __construct(
        CoinAmount $coinAmount,
        private AccountType $fromAccountType,
        private AccountType $toAccountType,
        public ?string $transferId = null,
    ) {
        $this->coin = $coinAmount->coin();
        $this->amount = $coinAmount->value();

        if ($this->transferId !== null) {
            assert($this->transferId, new InvalidArgumentException(
                sprintf('%s: $transferId must be non-empty string (`%s` provided)', __CLASS__, $this->transferId)
            ));
        }

        assert($this->amount > 0, new InvalidArgumentException(
            sprintf('%s: $amount must be greater than zero (`%f` provided)', __CLASS__, $this->amount)
        ));
    }

    public static function real(CoinAmount $coinAmount, AccountType $fromAccountType, AccountType $toAccountType, string $transferId): self
    {
        return new self($coinAmount, $fromAccountType, $toAccountType, $transferId);
    }

    public static function test(CoinAmount $coinAmount, AccountType $fromAccountType, AccountType $toAccountType): self
    {
        return new self($coinAmount, $fromAccountType, $toAccountType);
    }
}
