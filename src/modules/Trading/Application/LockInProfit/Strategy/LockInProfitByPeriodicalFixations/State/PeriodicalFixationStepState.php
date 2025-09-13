<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\State;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\Step\PeriodicalFixationStep;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateTimeImmutable;

final class PeriodicalFixationStepState
{
    public function __construct(
        public SymbolInterface $symbol,
        public Side $positionSide,
        public PeriodicalFixationStep $step,
        public DateTimeImmutable $lastFixationDatetime,
        public float $initialPositionSize,
        public float $totalClosed
    ) {
    }
}
