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
 * @see https://bybit-exchange.github.io/docs/v5/asset/unitransfer
 *
 * @see \App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Asset\Transfer\CoinUniversalTransferRequestTest
 */
final readonly class CoinUniversalTransferRequest extends AbstractByBitApiRequest
{
    public const URL = '/v5/asset/transfer/universal-transfer';

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
            'fromMemberId' => $this->fromMemberUid,
            'toMemberId' => $this->toMemberUid,
            'transferId' => $this->transferId,
        ];
    }

    public function __construct(
        CoinAmount $coinAmount,
        private AccountType $fromAccountType,
        private AccountType $toAccountType,
        private string $fromMemberUid,
        private string $toMemberUid,
        private string $transferId,
    ) {
        $this->coin = $coinAmount->coin();
        $this->amount = $coinAmount->value();

        assert($this->fromMemberUid !== $this->toMemberUid, new InvalidArgumentException(
            sprintf('%s: `fromMemberUid` cannot be equals to `toMemberUid', __CLASS__)
        ));

        assert($this->transferId, new InvalidArgumentException(
            sprintf('%s: $transferId must be non-empty string (`%s` provided)', __CLASS__, $this->transferId)
        ));

        assert($this->amount > 0, new InvalidArgumentException(
            sprintf('%s: $amount must be greater than zero (`%f` provided)', __CLASS__, $this->amount)
        ));
    }
}
