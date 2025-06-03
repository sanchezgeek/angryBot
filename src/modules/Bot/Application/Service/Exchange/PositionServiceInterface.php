<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange;

use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;

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
    public function getOpenedPositionsSymbols(array $except): array;

    /**
     * @return string[]
     */
    public function getOpenedPositionsRawSymbols(): array;
}
