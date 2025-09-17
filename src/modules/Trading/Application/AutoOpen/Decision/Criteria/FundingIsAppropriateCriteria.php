<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

final class FundingIsAppropriateCriteria extends AbstractOpenPositionCriteria
{
    public static function getAlias(): string
    {
        return 'funding-is-appropriate';
    }
}
