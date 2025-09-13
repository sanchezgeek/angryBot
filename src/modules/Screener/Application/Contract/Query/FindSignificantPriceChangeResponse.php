<?php

declare(strict_types=1);

namespace App\Screener\Application\Contract\Query;

use App\Domain\Value\Percent\Percent;
use App\Screener\Application\Contract\Dto\PriceChangeInfo;

final class FindSignificantPriceChangeResponse
{
    public function __construct(
        public PriceChangeInfo $info,
        public Percent $pricePercentChangeConsideredAsSignificant
    ) {
    }
}
