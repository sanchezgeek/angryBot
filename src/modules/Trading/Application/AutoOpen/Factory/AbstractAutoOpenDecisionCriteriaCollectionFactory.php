<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Factory;

use App\Trading\Application\AutoOpen\Decision\Criteria\AbstractOpenPositionCriteria;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('trading.autoOpen.decision.criteria_collection_factory')]
abstract class AbstractAutoOpenDecisionCriteriaCollectionFactory
{
    abstract public function supports(InitialPositionAutoOpenClaim $claim): bool;

    /**
     * @return AbstractOpenPositionCriteria[]
     */
    abstract public function create(InitialPositionAutoOpenClaim $claim): array;
}
