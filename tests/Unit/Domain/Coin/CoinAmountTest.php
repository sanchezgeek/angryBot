<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Coin;

use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Domain\Value\Percent\Percent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Coin\CoinAmount
 */
final class CoinAmountTest extends TestCase
{
    /**
     * @dataProvider createCases
     */
    public function testCreate(Coin $coin, float $amount, float $expectedAmount): void
    {
        $coinAmount = new CoinAmount($coin, $amount);

        self::assertEquals($coin, $coinAmount->coin());
        self::assertEquals($expectedAmount, $coinAmount->value());
    }

    private function createCases(): array
    {
        return [
            [Coin::USDT, 1.015, 1.015],
            [Coin::USDT, 0.015, 0.015],
            [Coin::USDT, 1.0015, 1.002],
            [Coin::USDT, 0.0015, 0.002],
            [Coin::BTC, 1.00000015, 1.00000015],
            [Coin::BTC, 0.00000015, 0.00000015],
            [Coin::BTC, 1.000000015, 1.00000002],
            [Coin::BTC, 0.000000015, 0.00000002],
        ];
    }

    /**
     * @dataProvider additionCases
     */
    public function testAddition(CoinAmount $initial, CoinAmount $add, CoinAmount $expectedRes): void
    {
        $result = $initial->add($add);

        self::assertEquals($expectedRes, $result);
    }

    private function additionCases(): array
    {
        return [
            [new CoinAmount(Coin::USDT, 1.00013), new CoinAmount(Coin::USDT, 0.00007), new CoinAmount(Coin::USDT, 1.0002)],
            [new CoinAmount(Coin::BTC, 1.000000013), new CoinAmount(Coin::BTC, 0.000000008), new CoinAmount(Coin::BTC, 1.000000021)],
        ];
    }

    /**
     * @dataProvider subtractCases
     */
    public function testSubtraction(CoinAmount $initial, CoinAmount $sub, CoinAmount $expectedRes): void
    {
        $result = $initial->sub($sub);

        self::assertEquals($expectedRes, $result);
    }

    private function subtractCases(): array
    {
        return [
            [new CoinAmount(Coin::USDT, 1.00013), new CoinAmount(Coin::USDT, 0.00007), new CoinAmount(Coin::USDT, 1.00006)],
            [new CoinAmount(Coin::BTC, 1.000000013), new CoinAmount(Coin::BTC, 0.000000008), new CoinAmount(Coin::BTC, 1.000000005)],
        ];
    }

    /**
     * @dataProvider addPercentCases
     */
    public function testAddPercent(CoinAmount $initial, Percent $percent, CoinAmount $expectedRes): void
    {
        $result = $initial->addPercent($percent);

        self::assertEquals($expectedRes, $result);
    }

    private function addPercentCases(): array
    {
        return [
            [new CoinAmount(Coin::USDT, 0.00015), Percent::string('10%'), new CoinAmount(Coin::USDT, 0.000165)],
            [new CoinAmount(Coin::BTC, 0.000000015), Percent::string('10%'), new CoinAmount(Coin::BTC, 0.0000000165)],
        ];
    }

    /**
     * @dataProvider percentCases
     */
    public function testGetPercent(CoinAmount $initial, Percent $percent, CoinAmount $expectedRes): void
    {
        $result = $initial->getPercentPart($percent);

        self::assertEquals($expectedRes, $result);
    }

    private function percentCases(): array
    {
        return [
            [new CoinAmount(Coin::USDT, 0.00016), Percent::string('50%'), new CoinAmount(Coin::USDT, 0.00008)],
            [new CoinAmount(Coin::BTC, 0.000000016), Percent::string('50%'), new CoinAmount(Coin::BTC, 0.000000008)],
        ];
    }
}
