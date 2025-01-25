<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bot\Application\Service\Exchange\Dto;

use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use PHPUnit\Framework\TestCase;

class ContractBalanceTest extends TestCase
{
    /**
     * @dataProvider createTestData
     */
    public function testCreate(Coin $coin): void
    {
        $total = 1.050;
        $available = 0.501;
        $free = 0.601;

        $balance = new ContractBalance($coin, $total, $available, $free, $free);

        self::assertEquals($coin, $balance->assetCoin);

        self::assertEquals(new CoinAmount($coin, $total), $balance->total);
        self::assertEquals($total, $balance->total());

        self::assertEquals(new CoinAmount($coin, $available), $balance->available);
        self::assertEquals($available, $balance->available());

        self::assertEquals(new CoinAmount($coin, $free), $balance->free);
        self::assertEquals($free, $balance->free());

        self::assertEquals(new CoinAmount($coin, $free), $balance->freeForLiquidation);
    }

    public function createTestData(): iterable
    {
        return [
            [Coin::USDT],
            [Coin::BTC]
        ];
    }
}