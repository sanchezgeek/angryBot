<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;

final class InstrumentAgeIsAppropriateCriteria extends AbstractOpenPositionCriteria
{
    public function getAlias(): string
    {
        return 'instrument-age-is-appropriate';
    }
}
