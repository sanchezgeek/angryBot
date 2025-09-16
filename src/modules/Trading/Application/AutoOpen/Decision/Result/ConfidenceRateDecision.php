<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Result;

use App\Domain\Value\Percent\Percent;

final class ConfidenceRateDecision
{
    /**
     * @param string $source
     * @param Percent $rate 0% and higher =)
     * @param string $info
     */
    public function __construct(
        public string $source,
        public Percent $rate,
        public string $info,
    ) {
        assert($this->rate->part() > 0);
    }
}
