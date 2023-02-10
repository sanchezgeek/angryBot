<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange;

use App\Bot\Application\Exception\MaxActiveCondOrdersCountReached;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;

interface PositionServiceInterface
{
    public function getOpenedPositionInfo(Symbol $symbol, Side $side): ?Position;

    public function getTickerInfo(Symbol $symbol): Ticker;

    /**
     * @return ?string Created stop order id or NULL if creation failed
     *
     * @throws MaxActiveCondOrdersCountReached
     */
    public function addStop(Position $position, Ticker $ticker, float $price, float $qty): ?string;

    /**
     * @return ?string Created buy order id or NULL if creation failed
     *
     * @throws MaxActiveCondOrdersCountReached
     */
    public function addBuyOrder(Position $position, Ticker $ticker, float $price, float $qty): ?string;
}
