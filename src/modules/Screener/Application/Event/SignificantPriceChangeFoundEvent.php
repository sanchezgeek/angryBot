<?php

declare(strict_types=1);

namespace App\Screener\Application\Event;

use App\Domain\Position\ValueObject\Side;
use App\EventBus\Event;
use App\Screener\Application\Contract\Query\FindSignificantPriceChangeResponse;

final class SignificantPriceChangeFoundEvent implements Event
{
    public function __construct(
        public FindSignificantPriceChangeResponse $info,
        public int $foundWhileSearchOnDaysDelta,
        public bool $tryOpenPosition = false
    ) {
    }

    public function positionSideToPositionLoss(): Side
    {
        $priceMovement = $this->info->info->getPriceMovement();

        return $priceMovement->isLossFor(Side::Sell) ? Side::Sell : Side::Buy;
    }
}
