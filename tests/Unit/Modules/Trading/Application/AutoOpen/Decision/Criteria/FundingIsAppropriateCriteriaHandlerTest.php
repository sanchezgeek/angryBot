<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Trading\Application\AutoOpen\Decision\Criteria;

use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use App\Trading\Application\AutoOpen\Decision\Criteria\FundingIsAppropriateCriteria;
use App\Trading\Application\AutoOpen\Decision\Criteria\FundingIsAppropriateCriteriaHandler;
use App\Trading\Application\AutoOpen\Decision\Result\ConfidenceRateDecision;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use App\Trading\Application\AutoOpen\Reason\ReasonForOpenPositionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class FundingIsAppropriateCriteriaHandlerTest extends TestCase
{
    const int FUNDING_ANALYSIS_HISTORICAL_PERIODS = 20;

    private MarketServiceInterface|MockObject $marketService;

    private FundingIsAppropriateCriteriaHandler $handler;

    protected function setUp(): void
    {
        $this->marketService = $this->createMock(MarketServiceInterface::class);

        $this->handler = new FundingIsAppropriateCriteriaHandler(
            $this->marketService
        );
    }

    /**
     * @dataProvider confidenceRateDecisionTestCases
     */
    public function testConfidenceRateDecision(
        array $fundingHistory,
        Side $positionSide,
        ConfidenceRateDecision $expectedDecision
    ): void {
        $symbol = SymbolEnum::AAVEUSDT;

        $this->marketService
            ->expects(self::once())
            ->method('getPreviousFundingRatesHistory')
            ->with($symbol, self::FUNDING_ANALYSIS_HISTORICAL_PERIODS)
            ->willReturn($fundingHistory)
        ;

        $this->marketService
            ->expects(self::once())
            ->method('getPreviousPeriodFundingRate')
            ->with($symbol)
            ->willReturn($fundingHistory[array_key_last($fundingHistory)])
        ;

        $claim = new InitialPositionAutoOpenClaim($symbol, $positionSide, $this->createMock(ReasonForOpenPositionInterface::class));

        $criteria = new FundingIsAppropriateCriteria();

        $result = $this->handler->makeConfidenceRateVote($claim, $criteria);

        self::assertEquals($expectedDecision, $result);
    }

    public function confidenceRateDecisionTestCases(): iterable
    {
        yield 'no volatility' => [
            [
                -0.00041, -0.00041, -0.00041, -0.00041, -0.00041, -0.00041, -0.00041, -0.00041, -0.00041,
                -0.00041, -0.00041, -0.00041, -0.00041, -0.00041, -0.00041, -0.00041, -0.00041, -0.00041,
            ],
            Side::Sell,
            self::result(1.1, 'Current funding: -0.000410. Historical mean: -0.000410. Trend: -0.000000 per period. Volatility: 0.000000. Z-score: -1.00. Extreme values: 0/18. Confidence multiplier: 1.10')
        ];

        ### 1 Низкая волатильность, положительный тренд (хорошо для шортов)
        $lowVolatilityPositiveTrend = [
            0.0001, 0.0002, 0.0003, 0.0004, 0.0005, 0.0006, 0.0007, 0.0008, 0.0009, 0.0010,
            0.0011, 0.0012, 0.0013, 0.0014, 0.0015, 0.0016, 0.0017, 0.0018, 0.0019, 0.0020
        ];

        yield '[applying for short] Низкая волатильность, положительный тренд' => [$lowVolatilityPositiveTrend, Side::Sell,
            self::result(1.5, 'Current funding: 0.002000. Historical mean: 0.001050. Trend: 0.000100 per period. Volatility: 0.000577. Z-score: 1.65. Extreme values: 1/20. Confidence multiplier: 1.50')
        ];

        yield '[applying for long] Низкая волатильность, положительный тренд' => [$lowVolatilityPositiveTrend, Side::Buy,
            self::result(0.9, 'Current funding: 0.002000. Historical mean: 0.001050. Trend: 0.000100 per period. Volatility: 0.000577. Z-score: 1.65. Extreme values: 1/20. Confidence multiplier: 0.90')
        ];

//        ### 2 Низкая волатильность, отрицательный тренд (хорошо для лонгов)
//        $lowVolatilityNegativeTrend = [
//            -0.0001, -0.0002, -0.0003, -0.0004, -0.0005, -0.0006, -0.0007, -0.0008, -0.0009, -0.0010,
//            -0.0011, -0.0012, -0.0013, -0.0014, -0.0015, -0.0016, -0.0017, -0.0018, -0.0019, -0.0020
//        ];
//
//        yield '[applying for short] Низкая волатильность, положительный тренд' => [$lowVolatilityPositiveTrend, Side::Sell,
//            self::result(1.32, 'Current funding: 0.000000. Historical mean: 0.001050. Trend: 0.000100 per period. Volatility: 0.000577. Z-score: 1.65. Extreme values: 1/20. Confidence multiplier: 1.32')
//        ];
//
//        yield '[applying for long] Низкая волатильность, положительный тренд' => [$lowVolatilityPositiveTrend, Side::Buy,
//            self::result(0.9, 'Current funding: 0.000000. Historical mean: 0.001050. Trend: 0.000100 per period. Volatility: 0.000577. Z-score: 1.65. Extreme values: 1/20. Confidence multiplier: 0.90')
//        ];

        ### 6 Резкий рост funding (от отрицательного к положительному)
        $changedFastFromNegativeToPositive = [
            -0.0015, -0.0010, -0.0005, 0.0000, 0.0005, 0.0010, 0.0015, 0.0020, 0.0025, 0.0030,
            0.0035, 0.0040, 0.0045, 0.0050, 0.0055, 0.0060, 0.0065, 0.0070, 0.0075, 0.0080
        ];

        yield '[applying for short] 6' => [$changedFastFromNegativeToPositive, Side::Sell,
            self::result(1.5, 'Current funding: 0.008000. Historical mean: 0.003250. Trend: 0.000500 per period. Volatility: 0.002883. Z-score: 1.65. Extreme values: 1/20. Confidence multiplier: 1.50')
        ];

        yield '[applying for long] 6' => [$changedFastFromNegativeToPositive, Side::Buy,
            self::result(0.9, 'Current funding: 0.008000. Historical mean: 0.003250. Trend: 0.000500 per period. Volatility: 0.002883. Z-score: 1.65. Extreme values: 1/20. Confidence multiplier: 0.90')
        ];

        ### 7 Резкое падение funding (от положительного к отрицательному)
        $changedFastFromPositiveToNegative = [
            0.0015, 0.0010, 0.0005, 0.0000, -0.0005, -0.0010, -0.0015, -0.0020, -0.0025, -0.0030,
            -0.0035, -0.0040, -0.0045, -0.0050, -0.0055, -0.0060, -0.0065, -0.0070, -0.0075, -0.0080
        ];

        yield '[applying for short] 7' => [$changedFastFromPositiveToNegative, Side::Sell,
            self::result(0.9, 'Current funding: -0.008000. Historical mean: -0.003250. Trend: -0.000500 per period. Volatility: 0.002883. Z-score: -1.65. Extreme values: 1/20. Confidence multiplier: 0.90')
        ];

        yield '[applying for long] 7' => [$changedFastFromPositiveToNegative, Side::Buy,
            self::result(1.5, 'Current funding: -0.008000. Historical mean: -0.003250. Trend: -0.000500 per period. Volatility: 0.002883. Z-score: -1.65. Extreme values: 1/20. Confidence multiplier: 1.50')
        ];

        ### 8 Стабильный положительный funding
        $positiveFundingHistory = [
            0.0008, 0.0007, 0.0009, 0.0008, 0.0007, 0.0009, 0.0008, 0.0007, 0.0009, 0.0008,
            0.0007, 0.0009, 0.0008, 0.0007, 0.0009, 0.0008, 0.0007, 0.0009, 0.0008, 0.0007
        ];

        yield '[applying for short] 8' => [$positiveFundingHistory, Side::Sell,
            self::result(1.287, 'Current funding: 0.000700. Historical mean: 0.000795. Trend: -0.000001 per period. Volatility: 0.000080. Z-score: -1.18. Extreme values: 0/20. Confidence multiplier: 1.29')
        ];

        yield '[applying for long] 8' => [$positiveFundingHistory, Side::Buy,
            self::result(1.21637, 'Current funding: 0.000700. Historical mean: 0.000795. Trend: -0.000001 per period. Volatility: 0.000080. Z-score: -1.18. Extreme values: 0/20. Confidence multiplier: 1.22')
        ];

        ### 8.1 Стабильный положительный funding с резким уменьшением в конце
        $positiveFundingHistoryI = [
            0.0008, 0.0007, 0.0009, 0.0008, 0.0007, 0.0009, 0.0008, 0.0007, 0.0009, 0.0008,
            0.0007, 0.0009, 0.0008, 0.0007, 0.0009, 0.0008, 0.0001, -0.0003, -0.0007, -0.0011
        ];

        yield '[applying for short] 8.1' => [$positiveFundingHistoryI, Side::Sell,
            self::result(0.70, 'Current funding: -0.001100. Historical mean: 0.000540. Trend: -0.000065 per period. Volatility: 0.000562. Z-score: -2.92. Extreme values: 0/20. Confidence multiplier: 0.70')
        ];

        yield '[applying for long] 8.1' => [$positiveFundingHistoryI, Side::Buy,
            self::result(1.5, 'Current funding: -0.001100. Historical mean: 0.000540. Trend: -0.000065 per period. Volatility: 0.000562. Z-score: -2.92. Extreme values: 2/20. Confidence multiplier: 1.50')
        ];
    }

    private static function result(float $rate, string $info): ConfidenceRateDecision
    {
        return new ConfidenceRateDecision(OutputHelper::shortClassName(FundingIsAppropriateCriteriaHandler::class), Percent::fromPart($rate, false), $info);
    }
}
