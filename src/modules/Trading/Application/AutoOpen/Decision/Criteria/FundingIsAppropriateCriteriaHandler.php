<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Helper\OutputHelper;
use App\Trading\Application\AutoOpen\Decision\OpenPositionPrerequisiteCheckerInterface;
use App\Trading\Application\AutoOpen\Decision\Result\OpenPositionPrerequisiteCheckResult;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use RuntimeException;

final class FundingIsAppropriateCriteriaHandler implements OpenPositionPrerequisiteCheckerInterface
{
    public const float FUNDING_THRESHOLD_FOR_SHORT = -0.0001;

    public function __construct(
        private readonly MarketServiceInterface $fundingProvider,
    ) {
    }

    public function supportsCriteriaCheck(AbstractOpenPositionCriteria $criteria): bool
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
}
