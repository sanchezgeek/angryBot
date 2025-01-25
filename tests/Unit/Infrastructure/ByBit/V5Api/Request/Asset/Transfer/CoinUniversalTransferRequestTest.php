<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Asset\Transfer;

use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Request\Asset\Transfer\CoinUniversalTransferRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function sprintf;
use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\API\V5\Request\Asset\Transfer\CoinUniversalTransferRequest
 */
final class CoinUniversalTransferRequestTest extends TestCase
{
    public function testCanCreate(): void
    {
        $transferId = uuid_create();

        $request = new CoinUniversalTransferRequest(
            new CoinAmount($coin = Coin::USDT, $amount = 100500.1),
            $fromAccountType = AccountType::UNIFIED,
            $toAccountType = AccountType::FUNDING,
            $fromMemberUid = 'fromMember',
            $toMemberUid = 'toMember',
            $transferId,
        );

        self::assertSame('/v5/asset/transfer/universal-transfer', $request->url());
        self::assertSame(Request::METHOD_POST, $request->method());
        self::assertTrue($request->isPrivateRequest());
        self::assertSame([
            'coin' => $coin->value,
            'amount' => (string)$amount,
            'fromAccountType' => $fromAccountType->value,
            'toAccountType' => $toAccountType->value,
            'fromMemberId' => $fromMemberUid,
            'toMemberId' => $toMemberUid,
            'transferId' => $transferId,
        ], $request->data());
    }

    public function testFailCreateWithSameFromAndToMemberUids(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage(sprintf('%s: `fromMemberUid` cannot be equals to `toMemberUid', CoinUniversalTransferRequest::class));

        new CoinUniversalTransferRequest(
            new CoinAmount($coin = Coin::USDT, $amount = 100500.1),
            AccountType::UNIFIED,
            AccountType::FUNDING,
            'fromMember',
            'fromMember',
            uuid_create(),
        );
    }
}
