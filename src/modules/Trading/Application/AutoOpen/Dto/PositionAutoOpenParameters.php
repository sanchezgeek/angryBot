<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Dto;

use App\Domain\Value\Percent\Percent;
use JsonSerializable;

final class PositionAutoOpenParameters implements JsonSerializable
{
    public function __construct(
        public Percent $percentOfDepositToUseAsMargin,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'minPercentOfDepositToUseAsMargin' => $this->percentOfDepositToUseAsMargin,
        ];
    }
}
