<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision;

use App\Trading\Application\AutoOpen\Decision\Criteria\AbstractOpenPositionCriteria;
use App\Trading\Application\AutoOpen\Decision\Result\ConfidenceRateDecision;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('trading.autoOpen.decision.confidence_rate_voter')]
interface OpenPositionConfidenceRateDecisionVoterInterface
{
    public function supportsMakeConfidenceRateVote(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): bool;
    public function makeConfidenceRateVote(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): ConfidenceRateDecision;
}
