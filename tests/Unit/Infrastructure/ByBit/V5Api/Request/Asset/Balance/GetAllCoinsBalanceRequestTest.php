<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Asset\Balance;

use App\Domain\Coin\Coin;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Request\Asset\Balance\GetAllCoinsBalanceRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \App\Infrastructure\ByBit\API\V5\Request\Asset\Transfer\CoinUniversalTransferRequest
 */
final class GetAllCoinsBalanceRequestTest extends TestCase
{
    public function testCanCreate(): void
    {
        $request = new GetAllCoinsBalanceRequest($accountType = AccountType::FUNDING, $coin = Coin::USDT);

        self::assertSame('/v5/asset/transfer/query-account-coins-balance', $request->url());
        self::assertSame(Request::METHOD_GET, $request->method());
        self::assertTrue($request->isPrivateRequest());
        self::assertSame([
            'accountType' => $accountType->value,
            'coin' => $coin->value,
        ], $request->data());
    }
}
