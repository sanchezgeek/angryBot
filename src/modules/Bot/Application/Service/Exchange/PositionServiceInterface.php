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
     * @return Position|null Null - if there is no opposite position opened
     */
    public function getOppositePosition(Position $position): ?Position;

    /**
     * @return string Created stop `orderId`
     */
    public function addConditionalStop(Position $position, Ticker $ticker, float $price, float $qty, TriggerBy $triggerBy = TriggerBy::IndexPrice): string;

    /**
     * @return string Created buy order `orderId`
     */
    public function marketBuy(Position $position, Ticker $ticker, float $price, float $qty): string;
}
