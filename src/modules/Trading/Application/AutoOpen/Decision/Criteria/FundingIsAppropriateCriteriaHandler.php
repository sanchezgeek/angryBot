<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Helper\OutputHelper;
use App\Trading\Application\AutoOpen\Decision\OpenPositionConfidenceRateDecisionVoterInterface;
use App\Trading\Application\AutoOpen\Decision\OpenPositionPrerequisiteCheckerInterface;
use App\Trading\Application\AutoOpen\Decision\Result\ConfidenceRateDecision;
use App\Trading\Application\AutoOpen\Decision\Result\OpenPositionPrerequisiteCheckResult;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use RuntimeException;

final class FundingIsAppropriateCriteriaHandler implements OpenPositionPrerequisiteCheckerInterface, OpenPositionConfidenceRateDecisionVoterInterface
{
    public const float FUNDING_THRESHOLD_FOR_SHORT = -0.0001;
    public const float FUNDING_THRESHOLD_FOR_LONG = 0.0001;
    public const float MAX_CONFIDENCE_MULTIPLIER = 1.5;
    public const float MIN_CONFIDENCE_MULTIPLIER = 0.5;
    public const int FUNDING_ANALYSIS_HISTORICAL_PERIODS = 20;


    public function __construct(
        private readonly MarketServiceInterface $fundingProvider,
    ) {
    }

    public function supportsCriteriaCheck(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): bool
    {
        return $criteria instanceof FundingIsAppropriateCriteria;
    }

    public function supportsMakeConfidenceRateVote(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): bool
    {
        return $criteria instanceof FundingIsAppropriateCriteria;
    }

    public function checkCriteria(
        InitialPositionAutoOpenClaim $claim,
        AbstractOpenPositionCriteria|FundingIsAppropriateCriteria $criteria
    ): OpenPositionPrerequisiteCheckResult {
        $symbol = $claim->symbol;
        $positionSide = $claim->positionSide;

        $funding = $this->fundingProvider->getPreviousPeriodFundingRate($symbol);

        if ($positionSide->isShort()) {
            if ($funding < self::FUNDING_THRESHOLD_FOR_SHORT) {
                return new OpenPositionPrerequisiteCheckResult(
                    false,
                    OutputHelper::shortClassName(self::class),
                    sprintf('prev funding on %s (%s) < %s', $symbol->name(), $funding, self::FUNDING_THRESHOLD_FOR_SHORT)
                );
            }
        } else {
            throw new RuntimeException('not implemented yet');
        }

        return new OpenPositionPrerequisiteCheckResult(true, OutputHelper::shortClassName(self::class), sprintf('current funding = %s', $funding));
    }

//    public function makeConfidenceRateVote(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): ConfidenceRate
//    {
//        $symbol = $claim->symbol;
//
//        $funding = $this->fundingProvider->getPreviousPeriodFundingRate($symbol);
//
//        if ($claim->positionSide->isShort()) {
//            $multiplier = 1 + $funding * 50; // положительный funding увеличивает уверенность для шортов
//        } else {
//            $multiplier = 1 - $funding * 50; // для лонгов: положительный funding уменьшает уверенность
//        }
//
//        $baseMultiplier = min(1.5, $multiplier);
//        $multiplier = max(0.8, min(1.5, $baseMultiplier));
//
//        return new ConfidenceRate(
//            OutputHelper::shortClassName($this),
//            Percent::fromPart($multiplier, false),
//            sprintf('Funding rate: %.6f => multiplier: %.3f', $funding, $multiplier)
//        );
//    }

    public function makeConfidenceRateVote(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): ConfidenceRateDecision
    {
        $symbol = $claim->symbol;
        $positionSide = $claim->positionSide;

        // Получаем текущее и исторические значения funding rate
        $currentFunding = $this->fundingProvider->getPreviousPeriodFundingRate($symbol);
        $historicalFunding = $this->fundingProvider->getPreviousFundingRatesHistory($symbol, self::FUNDING_ANALYSIS_HISTORICAL_PERIODS);

        // Анализируем исторические данные
        $historicalAnalysis = $this->analyzeHistoricalFunding($historicalFunding, $positionSide);

        // Рассчитываем итоговый множитель с учетом истории
        $baseMultiplier = $this->calculateBaseFundingMultiplier($currentFunding, $positionSide);
        $historicalMultiplier = $this->calculateHistoricalMultiplier($historicalAnalysis, $positionSide);

        $finalMultiplier = $baseMultiplier * $historicalMultiplier;
        $finalMultiplier = min(max($finalMultiplier, self::MIN_CONFIDENCE_MULTIPLIER), self::MAX_CONFIDENCE_MULTIPLIER);

        $info = $this->generateFundingInfo($currentFunding, $positionSide, $finalMultiplier, $historicalAnalysis);

        return new ConfidenceRateDecision(
            OutputHelper::shortClassName($this),
            Percent::fromPart(FloatHelper::round($finalMultiplier, 5), false),
            $info
        );
    }

    private function analyzeHistoricalFunding(array $historicalFunding, Side $positionSide): array
    {
        if (empty($historicalFunding)) {
            return [
                'trend' => 0,
                'volatility' => 0,
                'extreme_count' => 0,
                'mean' => 0,
                'z_score' => 0,
            ];
        }

        // Рассчитываем основные статистические показатели
        $mean = array_sum($historicalFunding) / count($historicalFunding);
        $variance = 0;

        foreach ($historicalFunding as $rate) {
            $variance += pow($rate - $mean, 2);
        }
        $variance /= count($historicalFunding);
        $stdDev = sqrt($variance);

        // Анализируем тренд с помощью линейной регрессии
        $trend = $this->calculateTrend($historicalFunding);

        // Считаем экстремальные значения
        $extremeCount = $this->countExtremeValues($historicalFunding, $positionSide, $mean, $stdDev);

        // Z-score текущего значения (относительно истории)
        $currentFunding = end($historicalFunding);
        $zScore = $stdDev > 0 ? ($currentFunding - $mean) / $stdDev : 0;

        return [
            'trend' => $trend,
            'volatility' => $stdDev,
            'extreme_count' => $extremeCount,
            'mean' => $mean,
            'z_score' => $zScore,
            'history' => $historicalFunding,
        ];
    }

    private function calculateTrend(array $fundingRates): float
    {
        $n = count($fundingRates);
        $sumX = $sumY = $sumXY = $sumX2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumX += $i;
            $sumY += $fundingRates[$i];
            $sumXY += $i * $fundingRates[$i];
            $sumX2 += $i * $i;
        }

        // Формула линейной регрессии: y = a + bx
        return ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
    }

    private function countExtremeValues(array $fundingRates, Side $positionSide, float $mean, float $stdDev): int
    {
        $extremeCount = 0;
        $threshold = $stdDev * 1.5; // 1.5 стандартных отклонения

        foreach ($fundingRates as $rate) {
            if ($positionSide->isShort()) {
                // Для шортов экстремальные значения - сильно положительные
                if ($rate > $mean + $threshold) {
                    $extremeCount++;
                }
            } else {
                // Для лонгов экстремальные значения - сильно отрицательные
                if ($rate < $mean - $threshold) {
                    $extremeCount++;
                }
            }
        }

        return $extremeCount;
    }

    private function calculateBaseFundingMultiplier(float $funding, Side $positionSide): float
    {
        // Более агрессивная реакция на текущее значение funding
        $aggressionFactor = 800; // Увеличиваем коэффициент влияния текущего funding

        if ($positionSide->isShort()) {
            // Для шортов: положительный funding хорош, отрицательный - плох
            $base = 1 + $aggressionFactor * $funding;
        } else {
            // Для лонгов: отрицательный funding хорош, положительный - плох
            $base = 1 - $aggressionFactor * $funding;
        }

        // Ограничиваем базовый множитель
        return max(0.5, min($base, 1.5));
    }

    private function calculateHistoricalMultiplier(array $historicalAnalysis, Side $positionSide): float
    {
        $multiplier = 1.0;

        // Значительно уменьшаем влияние исторических факторов
        $trendImpact = $historicalAnalysis['trend'] * 10000;

        // Тренд: максимальное влияние ±2%
        if ($positionSide->isShort()) {
            $multiplier *= 1.0 + min(max($trendImpact, 0) * 0.01, 0.02);
        } else {
            $multiplier *= 1.0 + min(max(-$trendImpact, 0) * 0.01, 0.02);
        }

        // Волатильность: максимальное влияние ±1%
        $relativeVolatility = $historicalAnalysis['volatility'] / max(0.0001, abs($historicalAnalysis['mean']));
        if ($relativeVolatility < 0.3) {
            $multiplier *= 1.01;
        } elseif ($relativeVolatility > 1.5) {
            $multiplier *= 0.99;
        }

        // Z-score: максимальное влияние ±2%
        $zScore = $historicalAnalysis['z_score'];
        if (abs($zScore) > 2) {
            $multiplier *= 0.98;
        } elseif (abs($zScore) < 0.5) {
            $multiplier *= 1.02;
        }

        return $multiplier;
    }

    private function generateFundingInfo(float $funding, Side $positionSide, float $multiplier, array $historicalAnalysis): string
    {
        $info = sprintf(
            "Current funding: %.6f. Historical mean: %.6f. ",
            $funding,
            $historicalAnalysis['mean']
        );

        $info .= sprintf(
            "Trend: %.6f per period. Volatility: %.6f. ",
            $historicalAnalysis['trend'],
            $historicalAnalysis['volatility']
        );

        $info .= sprintf(
            "Z-score: %.2f. Extreme values: %d/%d. ",
            $historicalAnalysis['z_score'],
            $historicalAnalysis['extreme_count'],
            count($historicalAnalysis['history'])
        );

        $info .= sprintf("Confidence multiplier: %.2f", $multiplier);

        return $info;
    }
}
