<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsUnderLiquidation;

use App\Bot\Domain\ValueObject\Symbol;

/**
 * @codeCoverageIgnore
 */
final readonly class CheckPositionIsUnderLiquidation
{
    public function __construct(
        public ?Symbol $symbol = null,
        public ?int $checkStopsOnPnlPercent = null,
        public ?int $percentOfLiquidationDistanceToAddStop = null,
        public ?float $acceptableStoppedPart = null,
        public ?float $warningPnlDistance = null,
        public float|int|null $criticalPartOfLiquidationDistance = null, // @todo | this is not about input message
    ) {
    }
}
