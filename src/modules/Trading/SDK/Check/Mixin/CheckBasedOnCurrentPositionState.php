<?php

declare(strict_types=1);

namespace App\Trading\SDK\Check\Mixin;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;

trait CheckBasedOnCurrentPositionState
{
    private readonly PositionServiceInterface $positionService;

    private function initPositionService(PositionServiceInterface $positionService): void
    {
        $this->positionService = $positionService;
    }

    public function enrichContextWithCurrentPositionState(SymbolInterface $symbol, Side $positionSide, TradingCheckContext $context): void
    {
        if (!$context->currentPositionState) {
            $context->currentPositionState = $this->positionService->getPosition($symbol, $positionSide);
        }
    }
}
