<?php

namespace App\Bot\Application\Service\Exchange\Trade;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

interface OrderServiceInterface
{
    /**
     * @return string `orderId` received from the exchange in case of success
     *
     * @throws CannotAffordOrderCostException
     */
    public function marketBuy(Symbol $symbol, Side $positionSide, float $qty): string;

    /**
     * @return string `orderId` received from the exchange in case of success
     */
    public function closeByMarket(Position $position, float $qty): string;

    /**
     * @return string `orderId` received from the exchange in case of success
     */
    public function addLimitTP(Position $position, float $qty, float $price): string;
}
