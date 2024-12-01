<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bot\Application\Service\Exchange\Dto;

use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Application\Service\Exchange\Dto\SpotBalance;
use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use PHPUnit\Framework\TestCase;

class SpotBalanceTest extends TestCase
{
    /**
     * @dataProvider createTestData
     */
    public function testCreate(Coin $coin): void
    {
        $total = 1.050;
        $available = 0.501;

        $balance = new SpotBalance($coin, $total, $available);

        self::assertEquals($coin, $balance->assetCoin);

        self::assertEquals(new CoinAmount($coin, $total), $balance->total);
        self::assertEquals($total, $balance->total());

        self::assertEquals(new CoinAmount($coin, $available), $balance->available);
        self::assertEquals($available, $balance->available());
    }

    public function createTestData(): iterable
    {
        return [
            [Coin::USDT],
            [Coin::BTC]
        ];
    }
}