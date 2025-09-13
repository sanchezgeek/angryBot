<?php

declare(strict_types=1);

namespace App\Buy\Application\Service\StopPlacement;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use JsonSerializable;
use Stringable;

final class StopPlacementStrategyContext implements JsonSerializable, Stringable
{
    public function __construct(
        public Ticker $ticker,
        public Position $position,
        public BuyOrder $buyOrder,
        public float $selectedStopPriceLength
    ) {
    }

    public function jsonSerialize(): mixed
    {
        return [
            'symbol'=> $this->position->symbol->name(),
            'side' => $this->position->side->title(),
            'ticker' => [
                'indexPrice' => $this->ticker->indexPrice->value()
            ],
            'position' => [
                'entryPrice' => $this->position->entryPrice,
            ],
            'buyOrder' => [
                'price' => $this->buyOrder->getPrice(),
                'id' => $this->buyOrder->getId(),
            ]
        ];
    }

    public function __toString()
    {
        return json_encode($this);
    }
}
