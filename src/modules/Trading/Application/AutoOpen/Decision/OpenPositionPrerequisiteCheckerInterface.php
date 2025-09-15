<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision;

use App\Trading\Application\AutoOpen\Decision\Criteria\AbstractOpenPositionCriteria;
use App\Trading\Application\AutoOpen\Decision\Result\OpenPositionPrerequisiteCheckResult;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('trading.autoOpen.decision.criteria_checker')]
interface OpenPositionPrerequisiteCheckerInterface
{
    public function supportsCriteriaCheck(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): bool;
    public function checkCriteria(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): OpenPositionPrerequisiteCheckResult;
}
