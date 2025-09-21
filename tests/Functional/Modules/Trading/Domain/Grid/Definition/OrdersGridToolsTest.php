<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Trading\Domain\Grid\Definition;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Trading\Enum\PriceDistanceSelector as Length;
use App\Domain\Value\Percent\Percent;
use App\Tests\Mixin\Trading\TradingParametersMocker;
use App\Trading\Domain\Grid\Definition\OrdersGridTools;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers \App\Trading\Domain\Grid\Definition\OrdersGridTools
 */
final class OrdersGridToolsTest extends KernelTestCase
{
    use TradingParametersMocker;

    private const SymbolEnum SYMBOL = SymbolEnum::AAVEUSDT;

    private OrdersGridTools $parser;

    protected function setUp(): void
    {
        $this->parser = new OrdersGridTools(self::getTradingParametersStubWithAllPredefinedLengths(self::SYMBOL));
    }

    /**
     * @dataProvider makeRawGridDefinitionCases
     */
    public function testMakeRawGridDefinition(
        string|float|Percent $from,
        string|float|Percent $to,
        float|Percent $positionSizePartPercent,
        int $stopsCount,
        array $additionalContexts,
        string $expectedResult
    ): void
    {
        self::assertEquals($expectedResult, $this->parser::makeRawGridDefinition($from, $to, $positionSizePartPercent, $stopsCount, $additionalContexts));
    }

    public static function makeRawGridDefinitionCases(): array
    {
        return [
            [
                '-very-very-very',
                '-not-so-very',
                30,
                5,
                [],
                '-very-very-very..-not-so-very|30%|5',
            ],
            [
                '-100%',
                '-not-so-very',
                30,
                5,
                ['wOO', 'aF'],
                '-100%..-not-so-very|30%|5|wOO,aF',
            ],
        ];
    }

    /**
     * @dataProvider transformToFinalRawDefinitionCases
     */
    public function testTransformToFinalRawDefinition(string $inputDefinition, string $expectedResult): void
    {
        $symbol = SymbolEnum::AAVEUSDT;

        self::assertEquals($expectedResult, $this->parser->transformToFinalPercentRangeDefinition($symbol, $inputDefinition));
    }

    public static function transformToFinalRawDefinitionCases(): array
    {
        return [
            [
                sprintf('%s..%s|30%%|5|wOO,aF', Length::Short->toStringWithNegativeSign(), Length::Long->toStringWithNegativeSign()),
                '-60.00%..-200.00%|30%|5|wOO,aF',
            ],
            [
                '-100%..-200%|30%|5|wOO,aF',
                '-100.00%..-200.00%|30%|5|wOO,aF',
            ],
            [
                '-100.00%..-200%|30%|5|wOO,aF',
                '-100.00%..-200.00%|30%|5|wOO,aF',
            ],
            [
                '-100%..-200.00%|30%|5|wOO,aF',
                '-100.00%..-200.00%|30%|5|wOO,aF',
            ],
            [
                sprintf('%s..-212.2%%|30%%|5|wOO,aF', Length::Short->toStringWithNegativeSign()),
                '-60.00%..-212.20%|30%|5|wOO,aF',
            ],
        ];
    }
}
