<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange\Trade;

final class ClosePositionResult
{
    public function __construct(
        public string $exchangeOrderId,
        public float $realClosedQty,
    ) {
    }
}
