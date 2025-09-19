<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Trading\Domain\Assertion;

use App\Domain\Position\ValueObject\Side;
use App\TechnicalAnalysis\Domain\Dto\Ath\PricePartOfAth;
use App\Trading\Domain\Assertion\IsPriceChangeSignificantAssertion;
use PHPUnit\Framework\TestCase;

final class IsPriceChangeSignificantAssertionTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function testAssertion(PricePartOfAth $athPart, Side $positionSideBias, float $expectedAtrBaseMultiplier): void
    {
        $assertion = new IsPriceChangeSignificantAssertion();
        $atrBaseMultiplier = $assertion->getAtrBaseMultiplier($positionSideBias, $athPart);

        self::assertEquals($expectedAtrBaseMultiplier, $atrBaseMultiplier);
    }

    public static function cases(): array
    {
        return [
            ['athPart' => PricePartOfAth::overLow(1.7), 'positionSideBias' => Side::Sell, 27],
            ['athPart' => PricePartOfAth::overLow(1.7), 'positionSideBias' => Side::Buy, 1],

            ['athPart' => PricePartOfAth::overLow(0.7), 'positionSideBias' => Side::Sell, 17],
            ['athPart' => PricePartOfAth::overLow(0.7), 'positionSideBias' => Side::Buy, 1],

            ['athPart' => PricePartOfAth::overLow(0.3), 'positionSideBias' => Side::Sell, 13],
            ['athPart' => PricePartOfAth::overLow(0.3), 'positionSideBias' => Side::Buy, 1],

            ['athPart' => PricePartOfAth::overLow(1), 'positionSideBias' => Side::Sell, 20],
            ['athPart' => PricePartOfAth::overLow(1), 'positionSideBias' => Side::Buy, 1],

            ['athPart' => PricePartOfAth::inTheBetween(0), 'positionSideBias' => Side::Sell, 10],
            ['athPart' => PricePartOfAth::inTheBetween(0), 'positionSideBias' => Side::Buy, 2],

            ['athPart' => PricePartOfAth::inTheBetween(0.1), 'positionSideBias' => Side::Sell, 9],
            ['athPart' => PricePartOfAth::inTheBetween(0.1), 'positionSideBias' => Side::Buy, 2],

            ['athPart' => PricePartOfAth::inTheBetween(0.3), 'positionSideBias' => Side::Sell, 7],
            ['athPart' => PricePartOfAth::inTheBetween(0.3), 'positionSideBias' => Side::Buy, 3],

            ['athPart' => PricePartOfAth::inTheBetween(0.5), 'positionSideBias' => Side::Sell, 5],
            ['athPart' => PricePartOfAth::inTheBetween(0.5), 'positionSideBias' => Side::Buy, 5],

            ['athPart' => PricePartOfAth::inTheBetween(0.7), 'positionSideBias' => Side::Sell, 3],
            ['athPart' => PricePartOfAth::inTheBetween(0.7), 'positionSideBias' => Side::Buy, 7],

            ['athPart' => PricePartOfAth::inTheBetween(0.9), 'positionSideBias' => Side::Sell, 2],
            ['athPart' => PricePartOfAth::inTheBetween(0.9), 'positionSideBias' => Side::Buy, 9],

            ['athPart' => PricePartOfAth::inTheBetween(1), 'positionSideBias' => Side::Sell, 2],
            ['athPart' => PricePartOfAth::inTheBetween(1), 'positionSideBias' => Side::Buy, 10],

            ['athPart' => PricePartOfAth::overHigh(1.25), 'positionSideBias' => Side::Sell, 1],
            ['athPart' => PricePartOfAth::overHigh(1.25), 'positionSideBias' => Side::Buy, 12.5],

            ['athPart' => PricePartOfAth::overHigh(1.25), 'positionSideBias' => Side::Sell, 1],
            ['athPart' => PricePartOfAth::overHigh(2.25), 'positionSideBias' => Side::Buy, 22.5],
        ];
    }
}
