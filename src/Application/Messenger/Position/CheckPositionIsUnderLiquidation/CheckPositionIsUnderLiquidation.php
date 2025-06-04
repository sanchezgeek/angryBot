<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsUnderLiquidation;

use App\Trading\Domain\Symbol\SymbolInterface;

/**
 * @codeCoverageIgnore
 */
final readonly class CheckPositionIsUnderLiquidation
{
    public function __construct(
        public ?SymbolInterface $symbol = null,
        public ?int $checkStopsOnPnlPercent = null,
        public ?int $percentOfLiquidationDistanceToAddStop = null,
        public ?float $acceptableStoppedPart = null,
        public ?float $warningPnlDistance = null,
        public float|int|null $criticalPartOfLiquidationDistance = null, // @todo | this is not about input message
    ) {
    }
}
