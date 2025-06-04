<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange;

use App\Bot\Domain\Position;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

interface PositionServiceInterface
{
    /**
     * @return Position|null Null - if position not opened
     */
    public function getPosition(SymbolInterface $symbol, Side $side): ?Position;

    /**
     * @return Position[]
     */
    public function getPositions(SymbolInterface $symbol): array;

    /**
     * @return string Created stop `orderId`
     */
    public function addConditionalStop(Position $position, float $price, float $qty, TriggerBy $triggerBy): string;

    /**
     * @return SymbolInterface[]
     */
    public function getOpenedPositionsSymbols(SymbolInterface ...$except): array;

    /**
     * @return string[]
     */
    public function getOpenedPositionsRawSymbols(): array;
}
