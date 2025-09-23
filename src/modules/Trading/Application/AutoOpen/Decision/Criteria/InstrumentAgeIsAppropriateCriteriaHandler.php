<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

use App\Helper\OutputHelper;
use App\TechnicalAnalysis\Application\Helper\TA;
use App\Trading\Application\AutoOpen\Decision\OpenPositionPrerequisiteCheckerInterface;
use App\Trading\Application\AutoOpen\Decision\Result\OpenPositionPrerequisiteCheckResult;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;

final class InstrumentAgeIsAppropriateCriteriaHandler implements OpenPositionPrerequisiteCheckerInterface
{
    public const int DAYS_THRESHOLD = 20;

    public function supportsCriteriaCheck(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): bool
    {
        return $criteria instanceof InstrumentAgeIsAppropriateCriteria;
    }

    public function checkCriteria(
        InitialPositionAutoOpenClaim $claim,
        AbstractOpenPositionCriteria|InstrumentAgeIsAppropriateCriteria $criteria
    ): OpenPositionPrerequisiteCheckResult {
        $symbol = $claim->symbol;

        $minDaysAllowed = self::DAYS_THRESHOLD;
        $age = TA::instrumentAge($symbol);

        if ($age->countOfDays() < $minDaysAllowed) {
            return new OpenPositionPrerequisiteCheckResult(
                false,
                OutputHelper::shortClassName(self::class),
                sprintf('age of %s less than %d days (%s)', $symbol->name(), $minDaysAllowed, $age)
            );
        }

        return new OpenPositionPrerequisiteCheckResult(true, OutputHelper::shortClassName(self::class), sprintf('instrument age = %s', $age));
    }

    // возможно тут тоже будут какие-то мысли
}
