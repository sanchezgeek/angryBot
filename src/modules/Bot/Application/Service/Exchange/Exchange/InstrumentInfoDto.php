<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange\Exchange;

use JsonSerializable;

class InstrumentInfoDto implements JsonSerializable
{
    public function __construct(
        public float $minOrderQty,
        public float $minOrderValue,
        public float $minLeverage,
        public float $maxLeverage,
        public float $tickSize,
    ) {
    }

    public function jsonSerialize(): mixed
    {
        return array_merge(get_object_vars($this), [
            'tickSize' => sprintf('%f', $this->tickSize)
        ]);
    }
}
