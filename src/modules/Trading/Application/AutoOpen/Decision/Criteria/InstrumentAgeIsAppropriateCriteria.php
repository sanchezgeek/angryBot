<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

final class InstrumentAgeIsAppropriateCriteria extends AbstractOpenPositionCriteria
{
    public static function getAlias(): string
    {
        return 'instrument-age-is-appropriate';
    }
}
