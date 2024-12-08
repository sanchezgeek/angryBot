<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange;

use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;

interface PositionServiceInterface
{
    /**
     * @return Position|null Null - if position not opened
     */
    public function getPosition(Symbol $symbol, Side $side): ?Position;

    /**
     * @return Position[]
     */
    public function getPositions(Symbol $symbol): array;

    /**
     * @return string Created stop `orderId`
     */
    public function addConditionalStop(Position $position, float $price, float $qty, TriggerBy $triggerBy): string;

    /**
     * @return Symbol[]
     */
    public function getOpenedPositionsSymbols(): array;
}
