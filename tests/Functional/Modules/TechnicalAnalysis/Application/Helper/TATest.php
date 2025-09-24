<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\TechnicalAnalysis\Application\Helper;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\TechnicalAnalysis\Application\Helper\TA;
use App\TechnicalAnalysis\Domain\Dto\Ath\PricePartOfAth;
use App\Tests\Mixin\TA\TaToolsProviderMocker;
use App\Tests\Stub\TA\TAToolsProviderStub;
use App\Tests\Stub\TA\TechnicalAnalysisToolsStub;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TATest extends KernelTestCase
{
    use TaToolsProviderMocker;

    private TAToolsProviderStub $taProviderStub;

    /**
     * @dataProvider simpleCases
     */
    public function testSimple(SymbolInterface $symbol, float $low, float $high, float $current, float $expectedResult): void
    {
        $this->initializeTaProviderStub()->addTechnicalAnalysisTools(
            new TechnicalAnalysisToolsStub($symbol)->addHighLowPricesResult($low, $high)
        );

        $result = TA::pricePartOfAth($symbol, $symbol->makePrice($current));

        self::assertEquals($expectedResult, $result->part());
    }

    public static function simpleCases(): iterable
    {
        $symbol = SymbolEnum::AAVEUSDT;

        yield 'middle' => [
            $symbol, 100, 200, 150, 0.5,
        ];

        yield 'moved over high' => [
            $symbol, 100, 200, 225, 1.25,
        ];

        yield 'moved over low' => [
            $symbol, 100, 200, 20, -0.80,
        ];
    }

    /**
     * @dataProvider cases
     */
    public function testExtended(SymbolInterface $symbol, float $low, float $high, float $current, PricePartOfAth $expectedResult): void
    {
        $this->initializeTaProviderStub()->addTechnicalAnalysisTools(
            new TechnicalAnalysisToolsStub($symbol)->addHighLowPricesResult($low, $high)
        );

        $result = TA::pricePartOfAthResult($symbol, $symbol->makePrice($current));

        self::assertEquals($expectedResult, $result);
    }

    public static function cases(): iterable
    {
        $symbol = SymbolEnum::AAVEUSDT;

        yield 'between' => [
            $symbol, 100, 200, 150, PricePartOfAth::inTheBetween(0.5)
        ];

        yield 'moved over high' => [
            $symbol, 100, 200, 225, PricePartOfAth::overHigh(1.25)
        ];

        yield 'moved over low' => [
            $symbol, 100, 200, 20, PricePartOfAth::overLow(0.80)
        ];
    }
}
