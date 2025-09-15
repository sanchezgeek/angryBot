<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

final class AthPricePartCriteria extends AbstractOpenPositionCriteria
{
    public function getAlias(): string
    {
        return 'at-h-l-price-appropriate';
    }
}
