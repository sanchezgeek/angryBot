<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Trading\Domain\Grid\Definition;

use App\Bot\Domain\ValueObject\SymbolEnum;
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
        $this->parser = new OrdersGridTools(self::getDefaultTradingParametersStubWithAllPredefinedLengths(self::SYMBOL));
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
    ): void {
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
            [
                '-100%',
                '-not-so-very/2',
                30,
                5,
                ['wOO', 'aF'],
                '-100%..-not-so-very/2|30%|5|wOO,aF',
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
                '-short..-long|30%|5|wOO,aF',
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
                '-short..-212.2%|30%|5|wOO,aF',
                '-60.00%..-212.20%|30%|5|wOO,aF',
            ],
            [
                '-short/2..-212.2%|30%|5|wOO,aF',
                '-30.00%..-212.20%|30%|5|wOO,aF',
            ],
            [
                '-short*2..-212.2%|30%|5|wOO,aF',
                '-120.00%..-212.20%|30%|5|wOO,aF',
            ],
            [
                '-short-very-short..-212.2%|30%|5|wOO,aF',
                '-110.00%..-212.20%|30%|5|wOO,aF',
            ],
            [
                '-short+100%..-212.2%+short|30%|5|wOO,aF',
                '40.00%..-152.20%|30%|5|wOO,aF',
            ],
            [
                '-short+100%..-long+100.00%|30%|5|wOO,aF',
                '40.00%..-100.00%|30%|5|wOO,aF',
            ],
            [
                '212.2%+very-short..212.2%+short|30%|5|wOO,aF',
                '262.20%..272.20%|30%|5|wOO,aF',
            ],
            [
                '-short+very-short..-212.2%+short|30%|5|wOO,aF',
                '-10.00%..-152.20%|30%|5|wOO,aF',
            ],
            [
                '-212.2%+very-short..212.2%+200.11%|30%|5|wOO,aF',
                '-162.20%..412.31%|30%|5|wOO,aF',
            ],

            [
                '-212.2%+very-short..-very-short+200.1%|30%|5|wOO,aF',
                '-162.20%..150.10%|30%|5|wOO,aF',
            ],
            [
                '200%-short..-300.1%+short|50%|5|wOO,aF',
                '140.00%..-240.10%|50%|5|wOO,aF',
            ],
            [
                'standard+0.00%..200.10%-short|50%|5|wOO,aF',
                '100.00%..140.10%|50%|5|wOO,aF',
            ],
            [
                '200%-short..0.05%+standard|50%|5|wOO,aF',
                '140.00%..100.05%|50%|5|wOO,aF',
            ],
            [
                '-very-short..-very-short-almost-immideately|50%|5|wOO,aF',
                '-50.00%..-60.00%|50%|5|wOO,aF',
            ],
            [
                'long-very-short/5-almost-immideately+10.51%..-very-short|50%|5|wOO,aF',
                '190.51%..-50.00%|50%|5|wOO,aF',
            ],
            [
                '-long-very-short/5-almost-immideately+10.51%..-10%+very-short|50%|5|wOO,aF',
                '-209.49%..40.00%|50%|5|wOO,aF',
            ],
            [
                '-long-very-short/5-almost-immideately+10.51%..-10%-very-short|50%|5|wOO,aF',
                '-209.49%..-60.00%|50%|5|wOO,aF',
            ],
            [
                '-long-very-short/5-almost-immideately+10.51%+1..-10%-very-short|50%|5|wOO,aF',
                '-208.49%..-60.00%|50%|5|wOO,aF',
            ],
            [
                '(-long-very-short)/5-almost-immideately+10.51%+1..-10%-very-short|50%|5|wOO,aF',
                '-48.49%..-60.00%|50%|5|wOO,aF',
            ],
        ];
    }
}
