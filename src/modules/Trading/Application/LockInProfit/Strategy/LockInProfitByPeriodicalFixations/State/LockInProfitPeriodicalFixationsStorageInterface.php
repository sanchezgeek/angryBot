<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\State;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\Step\PeriodicalFixationStep;
use App\Trading\Domain\Symbol\SymbolInterface;

interface LockInProfitPeriodicalFixationsStorageInterface
{
    public function getAllStoredKeys(): array;
    public function getStateByStoredKey(string $key): PeriodicalFixationStepState;

    public function getState(SymbolInterface $symbol, Side $positionSide, PeriodicalFixationStep $step): ?PeriodicalFixationStepState;
    public function saveState(PeriodicalFixationStepState $state): void;
    public function removeState(PeriodicalFixationStepState $state): void;
    public function removeStateBySymbolAndSide(SymbolInterface $symbol, Side $side): void;
}
