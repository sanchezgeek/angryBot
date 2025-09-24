<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Trading\Applicaiton\AutoOpen\Decision\Criteria\ByReason\SignificantPriceChangeFound;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Value\Percent\Percent;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mixin\TA\TaToolsProviderMocker;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Stub\TA\TechnicalAnalysisToolsStub;
use App\Trading\Application\AutoOpen\Decision\Criteria\ByReason\SignificantPriceChangeFound\AthPricePartCriteria;
use App\Trading\Application\AutoOpen\Decision\Criteria\ByReason\SignificantPriceChangeFound\AthPricePartCriteriaHandler;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use App\Trading\Application\AutoOpen\Reason\ReasonForOpenPositionInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers AthPricePartCriteriaHandler
 */
final class AthPricePartCriteriaHandlerTest extends KernelTestCase
{
    use ByBitV5ApiRequestsMocker;
    use TaToolsProviderMocker;

    /**
     * @dataProvider calculateAgeConfidenceCases
     */
    public function testMakeConfidenceRateVote(Side $positionSide, float $low, float $high, float $currentPrice, float $expectedResult): void
    {
        $symbol = SymbolEnum::AAVEUSDT;

        $this->haveTicker(TickerFactory::withEqualPrices($symbol, $currentPrice));
        $this->initializeTaProviderStub()->addTechnicalAnalysisTools(
            new TechnicalAnalysisToolsStub($symbol)->addHighLowPricesResult($low, $high)
        );

        $claim = new InitialPositionAutoOpenClaim($symbol, $positionSide, $this->createMock(ReasonForOpenPositionInterface::class));

        $criteria = new AthPricePartCriteria();

        $result = self::getHandler()->makeConfidenceRateVote($claim, $criteria);

        self::assertEquals(Percent::fromPart($expectedResult, false), $result->rate);
    }

    public static function calculateAgeConfidenceCases(): array
    {
        return [
            [Side::Sell, 100, 200, 10, 0.01],
            [Side::Sell, 100, 200, 70, 0.07],
            [Side::Sell, 100, 200, 80, 0.08],
            [Side::Sell, 100, 200, 90, 0.09],
            [Side::Sell, 100, 200, 99, 0.099],
            [Side::Sell, 100, 200, 100, 0.1],
            [Side::Sell, 100, 200, 111, 0.11],
            [Side::Sell, 100, 200, 150, 0.5],
            [Side::Sell, 100, 200, 250, 1.5],

            [Side::Buy, 100, 200, 50, 1.5],
            [Side::Buy, 100, 200, 80, 1.2],
            [Side::Buy, 100, 200, 90, 1.1],
            [Side::Buy, 100, 200, 100, 1],
            [Side::Buy, 100, 200, 110, 0.9],
            [Side::Buy, 100, 200, 140, 0.6],
            [Side::Buy, 100, 200, 190, 0.1],
            [Side::Buy, 100, 200, 200, 0.1],
            [Side::Buy, 100, 200, 210, 0.09],
            [Side::Buy, 100, 200, 220, 0.08],
            [Side::Buy, 100, 200, 250, 0.05],
            [Side::Buy, 100, 200, 290, 0.01],
            [Side::Buy, 100, 200, 490, 0.01],
        ];
    }

    private static function getHandler(): AthPricePartCriteriaHandler
    {
        return self::getContainer()->get(AthPricePartCriteriaHandler::class);
    }
}
