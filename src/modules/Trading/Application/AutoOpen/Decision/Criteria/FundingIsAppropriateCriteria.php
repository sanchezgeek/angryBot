<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

final class FundingIsAppropriateCriteria extends AbstractOpenPositionCriteria
{
    public function getAlias(): string
    {
        return 'funding-is-appropriate';
    }
}
