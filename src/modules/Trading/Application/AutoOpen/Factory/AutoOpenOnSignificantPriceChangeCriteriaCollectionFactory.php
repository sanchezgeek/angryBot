<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Factory;

use App\Trading\Application\AutoOpen\Decision\Criteria\AthPricePartCriteria;
use App\Trading\Application\AutoOpen\Decision\Criteria\AutoOpenNotDisabledCriteria;
use App\Trading\Application\AutoOpen\Decision\Criteria\FundingIsAppropriateCriteria;
use App\Trading\Application\AutoOpen\Decision\Criteria\InstrumentAgeIsAppropriateCriteria;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use App\Trading\Application\AutoOpen\Reason\AutoOpenOnSignificantPriceChangeReason;
use RuntimeException;

final class AutoOpenOnSignificantPriceChangeCriteriaCollectionFactory extends AbstractAutoOpenDecisionCriteriaCollectionFactory
{
    public function supports(InitialPositionAutoOpenClaim $claim): bool
    {
        return $claim->reason instanceof AutoOpenOnSignificantPriceChangeReason;
    }

    public function create(InitialPositionAutoOpenClaim $claim): array
    {
        if (!$this->supports($claim)) {
            throw new RuntimeException('unsupported reason');
        }

        return [
            new AutoOpenNotDisabledCriteria(),
            new InstrumentAgeIsAppropriateCriteria(),
            new FundingIsAppropriateCriteria(),
            new AthPricePartCriteria()
        ];
    }
}
