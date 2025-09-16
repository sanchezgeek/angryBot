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
        // Базовая логика (как в предыдущем примере)
        if ($positionSide->isShort()) {
            if ($funding >= 0) {
                $normalized = min($funding * 10000, 2.0);
                return 1.0 + $normalized * 0.15;
            } else {
                $normalized = max($funding, self::FUNDING_THRESHOLD_FOR_SHORT);
                $ratio = ($normalized - self::FUNDING_THRESHOLD_FOR_SHORT) / self::FUNDING_THRESHOLD_FOR_SHORT;
                return 1.0 + $ratio * 0.3;
            }
        } else {
            if ($funding <= 0) {
                $normalized = min(abs($funding) * 10000, 2.0);
                return 1.0 + $normalized * 0.15;
            } else {
                $normalized = min($funding, self::FUNDING_THRESHOLD_FOR_LONG);
                $ratio = ($normalized - self::FUNDING_THRESHOLD_FOR_LONG) / self::FUNDING_THRESHOLD_FOR_LONG;
                return 1.0 + $ratio * 0.3;
            }
        }
    }

    private function calculateHistoricalMultiplier(array $historicalAnalysis, Side $positionSide): float
    {
        $multiplier = 1.0;

        // Учет тренда - ОШИБКА: не учитывается направление позиции
        $trendImpact = $historicalAnalysis['trend'] * 10000;

        if ($positionSide->isShort()) {
            // Для шортов: положительный тренд (funding увеличивается) - хорошо
            $multiplier *= 1.0 + min(max($trendImpact, 0), 0.2);
        } else {
            // Для лонгов: отрицательный тренд (funding уменьшается) - хорошо
            $multiplier *= 1.0 + min(max(-$trendImpact, 0), 0.2);
        }

        // Учет волатильности (меньшая волатильность = больше уверенности)
        // ОШИБКА: абсолютные значения порогов могут быть неподходящими
        // Лучше использовать относительную волатильность
        $relativeVolatility = $historicalAnalysis['volatility'] / max(0.0001, abs($historicalAnalysis['mean']));

        if ($relativeVolatility < 0.5) {
            $multiplier *= 1.1; // Низкая волатильность увеличивает уверенность
        } elseif ($relativeVolatility > 2.0) {
            $multiplier *= 0.9; // Высокая волатильность уменьшает уверенность
        }

        // Учет экстремальных значений
        // ОШИБКА: не учитывается направление позиции при определении "экстремальности"
        $totalPeriods = count($historicalAnalysis['history']);
        $extremeRatio = $historicalAnalysis['extreme_count'] / max(1, $totalPeriods);

        if ($extremeRatio > 0.3) {
            // Много экстремальных значений - уменьшаем уверенность
            // Но для шортов экстремально положительные значения могут быть хороши
            if ($positionSide->isShort()) {
                $multiplier *= 0.9; // Умеренное уменьшение для шортов
            } else {
                $multiplier *= 0.8; // Сильное уменьшение для лонгов
            }
        }

        // Учет Z-score (отклонение от среднего)
        // ОШИБКА: не учитывается направление позиции
        $zScore = $historicalAnalysis['z_score'];

        if ($positionSide->isShort()) {
            // Для шортов: положительный z-score (выше среднего) - хорошо
            if ($zScore > 2) {
                $multiplier *= 1.2; // Сильно выше среднего - увеличиваем уверенность
            } elseif ($zScore > 1) {
                $multiplier *= 1.1; // Выше среднего - немного увеличиваем уверенность
            } elseif ($zScore < -2) {
                $multiplier *= 0.7; // Сильно ниже среднего - уменьшаем уверенность
            } elseif ($zScore < -1) {
                $multiplier *= 0.9; // Ниже среднего - немного уменьшаем уверенность
            }
        } else {
            // Для лонгов: отрицательный z-score (ниже среднего) - хорошо
            if ($zScore < -2) {
                $multiplier *= 1.2; // Сильно ниже среднего - увеличиваем уверенность
            } elseif ($zScore < -1) {
                $multiplier *= 1.1; // Ниже среднего - немного увеличиваем уверенность
            } elseif ($zScore > 2) {
                $multiplier *= 0.7; // Сильно выше среднего - уменьшаем уверенность
            } elseif ($zScore > 1) {
                $multiplier *= 0.9; // Выше среднего - немного уменьшаем уверенность
            }
        }

        return max(self::MIN_CONFIDENCE_MULTIPLIER, min($multiplier, self::MAX_CONFIDENCE_MULTIPLIER));
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
