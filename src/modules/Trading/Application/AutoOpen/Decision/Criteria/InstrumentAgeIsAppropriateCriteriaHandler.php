<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Helper\OutputHelper;
use App\TechnicalAnalysis\Application\Helper\TA;
use App\Trading\Application\AutoOpen\Decision\OpenPositionConfidenceRateDecisionVoterInterface;
use App\Trading\Application\AutoOpen\Decision\OpenPositionPrerequisiteCheckerInterface;
use App\Trading\Application\AutoOpen\Decision\Result\ConfidenceRateDecision;
use App\Trading\Application\AutoOpen\Decision\Result\OpenPositionPrerequisiteCheckResult;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;

/**
 * @see \App\Tests\Functional\Modules\Trading\Applicaiton\AutoOpen\Decision\Criteria\InstrumentAgeIsAppropriateCriteriaHandlerTest
 */
final class InstrumentAgeIsAppropriateCriteriaHandler implements OpenPositionPrerequisiteCheckerInterface, OpenPositionConfidenceRateDecisionVoterInterface
{
    public const int DAYS_THRESHOLD = 20;

    public const int ABSOLUTE_MINIMUM_AGE_DAYS = 3; // Абсолютный минимум
    public const int RECOMMENDED_MINIMUM_AGE_DAYS = 10; // Рекомендуемый минимум
    public const int MATURE_AGE_DAYS = 30; // Возраст "зрелости"
    public const int CONFIDENT_AGE = 70;

    public function supportsCriteriaCheck(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): bool
    {
        return $criteria instanceof InstrumentAgeIsAppropriateCriteria;
    }

    public function supportsMakeConfidenceRateVote(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): bool
    {
        return $criteria instanceof InstrumentAgeIsAppropriateCriteria;
    }

    public function checkCriteria(
        InitialPositionAutoOpenClaim $claim,
        AbstractOpenPositionCriteria|InstrumentAgeIsAppropriateCriteria $criteria
    ): OpenPositionPrerequisiteCheckResult {
        $symbol = $claim->symbol;

        $minDaysAllowed = self::ABSOLUTE_MINIMUM_AGE_DAYS;
        $age = TA::instrumentAge($symbol);

        if ($age->countOfDays() < $minDaysAllowed) {
            return new OpenPositionPrerequisiteCheckResult(
                false,
                OutputHelper::shortClassName(self::class),
                sprintf('age of %s less than absolute minimum[%d] days (%s)', $symbol->name(), $minDaysAllowed, $age)
            );
        }

        // Дополнительная проверка объема для очень молодых активов
//        if ($age->countOfDays() < self::RECOMMENDED_MINIMUM_AGE_DAYS) {
//            $volumeAnalysis = $this->analyzeVolumeStability($symbol);
//            if (!$volumeAnalysis['is_stable']) {
//                return new OpenPositionPrerequisiteCheckResult(
//                    false,
//                    OutputHelper::shortClassName(self::class),
//                    sprintf('Age of %s is %d days but volume is not stable enough',
//                        $symbol->name(),
//                        $age->countOfDays())
//                );
//            }
//        }

        return new OpenPositionPrerequisiteCheckResult(true, OutputHelper::shortClassName(self::class), sprintf('instrument age = %s', $age));
    }

    public function makeConfidenceRateVote(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): ConfidenceRateDecision
    {
        $symbol = $claim->symbol;
        $age = TA::instrumentAge($symbol);
        $ageDays = $age->countOfDays();

        // Базовый расчет уверенности на основе возраста
        $ageConfidence = $this->calculateAgeConfidence($ageDays);

        // Корректировка на основе объема (если актив молодой)
//        if ($ageDays < self::MATURE_AGE_DAYS) {
//            $volumeAnalysis = $this->analyzeVolumeStability($symbol);
//            $volumeConfidence = $this->calculateVolumeConfidence($volumeAnalysis);
//            $finalConfidence = min($ageConfidence, $volumeConfidence);
//        } else {
            $finalConfidence = $ageConfidence;
//        }

        return new ConfidenceRateDecision(
            OutputHelper::shortClassName($this),
            Percent::fromPart($finalConfidence, false),
            sprintf('Instrument age confidence: %.2f (%d days)', $finalConfidence, $ageDays)
        );
    }

    public function calculateAgeConfidence(float $ageDays): float
    {
        $points = [
            0 => 0.0,
            1 => 0.17,
            3 => 0.25,
            6 => 0.3,
            10 => 0.5,
            20 => 0.75,
            30 => 0.85,
            40 => 0.95,
            50 => 0.96,
            60 => 0.97,
            70 => 1.0
        ];

        // Находим ближайшие точки для интерполяции
        $prevAge = 0;
        $prevConf = 0.0;

        foreach ($points as $age => $confidence) {
            if ($ageDays <= $age) {
                if ($ageDays == $age) {
                    return $confidence;
                }
                // Линейная интерполяция
                $ratio = ($ageDays - $prevAge) / ($age - $prevAge);
                return FloatHelper::round($prevConf + $ratio * ($confidence - $prevConf));
            }
            $prevAge = $age;
            $prevConf = $confidence;
        }

        return 1.0;
    }

//    public function calculateAgeConfidence(float $ageDays): float
//    {
//        // Нелинейное преобразование: уверенность быстро растет первые 30 дней, затем медленнее
//        if ($ageDays >= self::CONFIDENT_AGE) {
//            return 1.0; // Максимальная уверенность для активов старше 90 дней
//        }
//
//        // Логистическая функция для плавного роста уверенности
//        $k = 0.1; // Коэффициент крутизны
//        $x0 = self::MATURE_AGE_DAYS; // Точка перегиба (30 дней)
//
//        return FloatHelper::round(1 / (1 + exp(-$k * ($ageDays - $x0))));
//    }

//    public function calculateAgeConfidence(float $ageDays): float
//    {
//        if ($ageDays >= self::CONFIDENT_AGE) {
//            return 1.0;
//        }
//
//        // Комбинированная функция: степенная в начале + логистическая в конце
//        if ($ageDays <= 20) {
//            // Степенная функция для быстрого роста в начале
//            $normalizedAge = $ageDays / 20;
//            return FloatHelper::round(0.9 * pow($normalizedAge, 0.6) + 0.1);
//        } else {
//            // Логистическая функция для плавного выхода на плато
//            $k = 0.15; // Более крутой коэффициент
//            $x0 = 35;   // Сдвиг точки перегиба
//            $logistic = 1 / (1 + exp(-$k * ($ageDays - $x0)));
//            return FloatHelper::round(0.1 + 0.9 * $logistic);
//        }
//    }
}
