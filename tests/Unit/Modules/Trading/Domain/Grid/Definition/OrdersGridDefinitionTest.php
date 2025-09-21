<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Trading\Domain\Grid\Definition;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\PriceRange;
use App\Domain\Value\Percent\Percent;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinition;
use App\Trading\Domain\Symbol\SymbolInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers OrdersGridDefinition
 */
final class OrdersGridDefinitionTest extends TestCase
{
    /**
     * @dataProvider creationTestCases
     */
    public function testFactory(SymbolInterface $symbol, Side $positionSide, float $priceToRelate, string $def, OrdersGridDefinition $expectedResult): void
    {
        $result = OrdersGridDefinition::create($def, $symbol->makePrice($priceToRelate), $positionSide, $symbol);

        self::assertEquals($expectedResult, $result);
    }

    public static function creationTestCases(): array
    {
        $symbol = SymbolEnum::BTCUSDT;

        return [
            [
                $symbol, Side::Sell, 100000, '-50.00%..-200.00%|30%|5|wOO,aF',
                new OrdersGridDefinition(PriceRange::create(100500, 102000, $symbol), Percent::string('30%'), 5, ['wOO', 'aF'])
            ],
            [
                $symbol, Side::Buy, 100000, '-50.00%..-200.00%|30%|5|wOO',
                new OrdersGridDefinition(PriceRange::create(99500, 98000, $symbol), Percent::string('30%'), 5, ['wOO'])
            ],
        ];
    }

    public function testCloneWithNewPercent(): void
    {
        $symbol = SymbolEnum::AAVEUSDT;
        $newPercent = Percent::string('50%');

        $definition = new OrdersGridDefinition(PriceRange::create(99500, 98000, $symbol), Percent::string('30%'), 5, ['wOO']);

        self::assertEquals(
            new OrdersGridDefinition(PriceRange::create(99500, 98000, $symbol), $newPercent, 5, ['wOO']),
            $definition->cloneWithNewPercent($newPercent)
        );
    }
}
