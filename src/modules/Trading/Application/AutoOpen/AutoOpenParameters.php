<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen;

use App\Domain\Trading\Enum\RiskLevel;
use JsonSerializable;

final class AutoOpenParameters implements JsonSerializable
{
    public function __construct(
        public RiskLevel $usedRiskLevel,
        public float $minPercentOfDepositToUseAsMargin,
        public float $maxPercentOfDepositToUseAsMargin,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'usedRiskLevel' => $this->usedRiskLevel,
            'minPercentOfDepositToUseAsMargin' => $this->minPercentOfDepositToUseAsMargin,
            'maxPercentOfDepositToUseAsMargin' => $this->maxPercentOfDepositToUseAsMargin
        ];
    }
}
