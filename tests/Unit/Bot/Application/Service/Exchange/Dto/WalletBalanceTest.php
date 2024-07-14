<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bot\Application\Service\Exchange\Dto;

use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use PHPUnit\Framework\TestCase;
use Throwable;

use function sprintf;
use function str_contains;

class WalletBalanceTest extends TestCase
{
    /**
     * @dataProvider createTestData
     */
    public function testCreate(Coin $coin, AccountType $accountType): void
    {
        $total = 1.050;
        $available = 0.501;
        $free = 0.601;

        $balance = new WalletBalance($accountType, $coin, $total, $available, $free);

        self::assertEquals($accountType, $balance->accountType);
        self::assertEquals($coin, $balance->assetCoin);

        self::assertEquals(new CoinAmount($coin, $total), $balance->total);
        self::assertEquals($total, $balance->total());

        self::assertEquals(new CoinAmount($coin, $available), $balance->available);
        self::assertEquals($available, $balance->available());

        try {
            self::assertEquals(new CoinAmount($coin, $free), $balance->free);
            self::assertEquals($free, $balance->free());
        } catch (Throwable $e) {
            if ($accountType === AccountType::SPOT) {
                self::assertTrue(str_contains($e->getMessage(), sprintf('incorrect usage of %s::free for SPOT accountType', WalletBalance::class)));
            } else {
                throw $e;
            }
        }
    }

    public function createTestData(): iterable
    {
        yield 'USDT SPOT' => [Coin::USDT, AccountType::SPOT];
        yield 'USDT CONTRACT' => [Coin::USDT, AccountType::CONTRACT];

        yield 'BTC SPOT' => [Coin::BTC, AccountType::SPOT];
        yield 'BTC CONTRACT' => [Coin::BTC, AccountType::CONTRACT];
    }
}