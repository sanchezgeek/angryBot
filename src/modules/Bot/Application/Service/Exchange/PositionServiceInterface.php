<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange;

use App\Bot\Application\Exception\ApiRateLimitReached;
use App\Bot\Application\Exception\CannotAffordOrderCost;
use App\Bot\Application\Exception\MaxActiveCondOrdersQntReached;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
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
     * @return ?string Created stop order id or NULL if creation failed
     *
     * @throws MaxActiveCondOrdersQntReached|ApiRateLimitReached|CannotAffordOrderCost
     */
    public function addStop(Position $position, Ticker $ticker, float $price, float $qty): ?string;

    /**
     * @return ?string Created buy order id or NULL if creation failed
     *
     * @throws MaxActiveCondOrdersQntReached|ApiRateLimitReached|CannotAffordOrderCost
     */
    public function addBuyOrder(Position $position, Ticker $ticker, float $price, float $qty): ?string;
}
