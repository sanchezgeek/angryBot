<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Trading\Applicaiton\AutoOpen\Decision\Criteria\ByReason\SignificantPriceChangeFound;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Value\Percent\Percent;
use App\Screener\Application\Contract\Dto\PriceChangeInfo;
use App\Screener\Application\Contract\Query\FindSignificantPriceChangeResponse;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mixin\TA\TaToolsProviderMocker;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mixin\Trading\TradingParametersMocker;
use App\Tests\Stub\TA\TechnicalAnalysisToolsStub;
use App\Trading\Application\AutoOpen\Decision\Criteria\ByReason\SignificantPriceChangeFound\AthPricePartCriteria;
use App\Trading\Application\AutoOpen\Decision\Criteria\ByReason\SignificantPriceChangeFound\AthPricePartCriteriaHandler;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use App\Trading\Application\AutoOpen\Reason\AutoOpenOnSignificantPriceChangeReason;
use App\Trading\Application\AutoOpen\Reason\ReasonForOpenPositionInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateInterval;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers AthPricePartCriteriaHandler
 */
final class AthPricePartCriteriaHandlerTest extends KernelTestCase
{
    use ByBitV5ApiRequestsMocker;
    use TaToolsProviderMocker;
    use TradingParametersMocker;

    const int MAX_THRESHOLD = 80;

    protected function setUp(): void
    {
        self::createTradingParametersStub();
    }

    private static function prepareFindSignificantPriceChangeResponse(
        SymbolInterface $symbol,
        int $daysDelta,
        float $fromPrice,
        float $toPrice,
    ): FindSignificantPriceChangeResponse {
        $toDate = new DateTimeImmutable();
        $fromDate = $toDate->sub(new DateInterval(sprintf('P%dD', $daysDelta)));

        return new FindSignificantPriceChangeResponse(
            new PriceChangeInfo(
                $symbol,
                $fromDate,
                $symbol->makePrice($fromPrice),
                $toDate,
                $symbol->makePrice($toPrice),
                $daysDelta,
            ),
            // @todo calc based on specified one day significant price change
            Percent::fromPart(($toPrice - $fromPrice) / $fromPrice, false)
        );
    }

    private static function prepareFindSignificantPriceChangeResponse2(
        SymbolInterface $symbol,
        Percent $oneDayPriceChangePercent,
        float $multiplier,
        float $daysDelta,
        float $fromPrice,
    ): FindSignificantPriceChangeResponse {
        $toDate = new DateTimeImmutable();
        $fromDate = new DateTimeImmutable()->setTimestamp((int)($toDate->getTimestamp() - 86400 * $daysDelta));

        // @todo longs
        $toPrice = $fromPrice + $oneDayPriceChangePercent->value() * $multiplier * $fromPrice / 100;

        return new FindSignificantPriceChangeResponse(
            new PriceChangeInfo(
                $symbol,
                $fromDate,
                $symbol->makePrice($fromPrice),
                $toDate,
                $symbol->makePrice($toPrice),
                $daysDelta,
            ),
            // @todo calc based on specified one day significant price change
            Percent::fromPart(($toPrice - $fromPrice) / $fromPrice, false)
        );
    }

    /**
     * @dataProvider testCheckCriteriaCases
     */
    public function testCheckCriteria(
        Side $bias,
        Percent $oneDayPriceChangePercent,
        float $priceChangedFrom,
        float $significantChangesQnt,
        float $daysDelta,
        Percent $expectedThreshold,
    ): void {
        $positionSide = $bias;
        $symbol = SymbolEnum::GRIFFAINUSDT;

        $significantPriceChangeResponse = self::prepareFindSignificantPriceChangeResponse2($symbol, $oneDayPriceChangePercent, $significantChangesQnt, $daysDelta, $priceChangedFrom);

        $claim = new InitialPositionAutoOpenClaim($symbol, $positionSide, new AutoOpenOnSignificantPriceChangeReason($significantPriceChangeResponse));

        $this->getMockedTradingParametersStub()->addSignificantPriceChangeResult($symbol, $oneDayPriceChangePercent);

        $criteria = new AthPricePartCriteria();

        $result = self::getHandler()->getAthThreshold($claim, $criteria);

        self::assertEquals($expectedThreshold, $result);
    }

    public static function testCheckCriteriaCases(): array
    {
        return [
            // growth slow
            [Side::Sell, Percent::string('18%'), 0.0711, 1, 3, new Percent(self::MAX_THRESHOLD)],
            [Side::Sell, Percent::string('18%'), 0.0711, 3, 3, new Percent(self::MAX_THRESHOLD)],
            [Side::Sell, Percent::string('18%'), 0.0711, 4, 3, new Percent(60.002)],
            [Side::Sell, Percent::string('18%'), 0.0711, 5, 3, new Percent(48.0)],
            [Side::Sell, Percent::string('18%'), 0.0711, 6, 3, new Percent(39.999)],
            [Side::Sell, Percent::string('18%'), 0.0711, 7, 3, new Percent(34.284)],
            [Side::Sell, Percent::string('18%'), 0.0711, 8, 3, new Percent(30.001)],
            [Side::Sell, Percent::string('18%'), 0.0711, 10, 3, new Percent(24.0)],
            [Side::Sell, Percent::string('18%'), 0.0711, 70, 3, new Percent(3.429)],

//            // growth fast
            [Side::Sell, Percent::string('18%'), 0.0711, 0.5, 0.6, new Percent(self::MAX_THRESHOLD)],
            [Side::Sell, Percent::string('18%'), 0.0711, 1.5, 0.6, new Percent(self::MAX_THRESHOLD)],

            [Side::Sell, Percent::string('18%'), 0.0711, 1, 1.1, new Percent(self::MAX_THRESHOLD)],
            [Side::Sell, Percent::string('18%'), 0.0711, 1.9, 1.1, new Percent(self::MAX_THRESHOLD)],
            [Side::Sell, Percent::string('18%'), 0.0711, 3, 1.1, new Percent(53.339)],
            [Side::Sell, Percent::string('18%'), 0.0711, 4, 1.1, new Percent(40.002)],
            [Side::Sell, Percent::string('18%'), 0.0711, 4, 0.5, new Percent(40.002)],
            [Side::Sell, Percent::string('18%'), 0.0711, 5, 1.1, new Percent(32.0)],
            [Side::Sell, Percent::string('18%'), 0.0711, 5, 1.6, new Percent(32.0)],
            [Side::Sell, Percent::string('18%'), 0.0711, 6, 1.1, new Percent(26.666)],
            [Side::Sell, Percent::string('18%'), 0.0711, 10, 1.1, new Percent(16.0)],

            [Side::Sell, Percent::string('18%'), 0.0711, 1, 2.1, new Percent(self::MAX_THRESHOLD)],
            [Side::Sell, Percent::string('18%'), 0.0711, 2, 2.1, new Percent(self::MAX_THRESHOLD)],
            [Side::Sell, Percent::string('18%'), 0.0711, 3, 2.1, new Percent(56.006)],
            [Side::Sell, Percent::string('18%'), 0.0711, 4, 2.5, new Percent(50.002)],
            [Side::Sell, Percent::string('18%'), 0.0711, 5, 2.5, new Percent(40.0)],
            [Side::Sell, Percent::string('18%'), 0.0711, 6, 2.5, new Percent(33.332)],
        ];
    }

    /**
     * @dataProvider makeConfidenceRateVoteCases
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

    public static function makeConfidenceRateVoteCases(): array
    {
        return [
            [Side::Sell, 100, 200, 10, 0.01],
            [Side::Sell, 100, 200, 70, 0.07],
            [Side::Sell, 100, 200, self::MAX_THRESHOLD, 0.08],
            [Side::Sell, 100, 200, 90, 0.09],
            [Side::Sell, 100, 200, 99, 0.099],
            [Side::Sell, 100, 200, 100, 0.1],
            [Side::Sell, 100, 200, 111, 0.11],
            [Side::Sell, 100, 200, 150, 0.5],
            [Side::Sell, 100, 200, 250, 1.5],

            [Side::Buy, 100, 200, 50, 1.5],
            [Side::Buy, 100, 200, self::MAX_THRESHOLD, 1.2],
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
