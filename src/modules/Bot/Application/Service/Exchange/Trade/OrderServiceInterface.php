<?php

namespace App\Bot\Application\Service\Exchange\Trade;

use App\Bot\Domain\Position;

interface OrderServiceInterface
{
    public function closeByMarket(Position $position, float $qty): string;

    public function addLimitTP(Position $position, float $qty, float $price): string;
}
