<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bot\Application\Service\Exchange\Dto;

use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use PHPUnit\Framework\TestCase;

class WalletBalanceTest extends TestCase
{
    /**
     * @dataProvider createTestData
     */
    public function testCreate(Coin $coin, AccountType $accountType): void
    {
        $total = 1.050;
        $available = 0.501;

        $balance = new WalletBalance($accountType, $coin, $total, $available);

        self::assertEquals($accountType, $balance->accountType);
        self::assertEquals($coin, $balance->assetCoin);

        self::assertEquals(new CoinAmount($coin, $total), $balance->total);
        self::assertEquals($total, $balance->total());

        self::assertEquals(new CoinAmount($coin, $available), $balance->available);
        self::assertEquals($available, $balance->available());
    }

    public function createTestData(): iterable
    {
        yield 'USDT SPOT' => [Coin::USDT, AccountType::SPOT];
        yield 'USDT CONTRACT' => [Coin::USDT, AccountType::CONTRACT];

        yield 'BTC SPOT' => [Coin::BTC, AccountType::SPOT];
        yield 'BTC CONTRACT' => [Coin::BTC, AccountType::CONTRACT];
    }
}