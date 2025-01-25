<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange\Exchange;

class InstrumentInfoDto
{
    public function __construct(
        public float $minOrderQty,
        public float $minOrderValue,
    ) {
    }
}