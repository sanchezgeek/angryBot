<?php

namespace App\Bot\Application\Service\Exchange\Trade;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;

interface OrderServiceInterface
{
    /**
     * @return string `orderId` received from the exchange in case of success
     *
     * @throws CannotAffordOrderCostException
     *
     * @todo | replace calls with handler
     */
    public function marketBuy(SymbolInterface $symbol, Side $positionSide, float $qty): string;

    /**
     * @return string `orderId` received from the exchange in case of success
     */
    public function closeByMarket(Position $position, float $qty): string;

    /**
     * @return string `orderId` received from the exchange in case of success
     */
    public function addLimitTP(Position $position, float $qty, float $price): string;
}
