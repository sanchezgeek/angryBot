<?php

declare(strict_types=1);

namespace App\Bot\Domain;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;

final readonly class Ticker
{
    public Price $markPrice;
    public Price $indexPrice;
    public Price $lastPrice;

    public function __construct(
        public Symbol $symbol,
        float|Price $markPrice,
        float|Price $indexPrice,
        float|Price $lastPrice,
    ) {
        $this->lastPrice = $lastPrice instanceof Price ? $lastPrice : $this->symbol->makePrice($lastPrice);
        $this->markPrice = $markPrice instanceof Price ? $markPrice : $this->symbol->makePrice($markPrice);
        $this->indexPrice = $lastPrice instanceof Price ? $indexPrice : $this->symbol->makePrice($indexPrice);
    }

    public function isIndexAlreadyOverStop(Side $positionSide, float $price): bool
    {
        return $positionSide->isShort() ? $this->indexPrice->greaterOrEquals($price) : $this->indexPrice->lessOrEquals($price);
    }

    public function isIndexAlreadyOverBuyOrder(Side $positionSide, float $price): bool
    {
        return $positionSide->isShort() ? $this->indexPrice->lessOrEquals($price) : $this->indexPrice->greaterOrEquals($price);
    }

    public function isLastPriceOverIndexPrice(Side $positionSide): bool
    {
        return $positionSide->isShort() ? $this->lastPrice->lessThan($this->indexPrice) : $this->lastPrice->greaterThan($this->indexPrice);
    }

    public function getMinPrice(): Price
    {
        return min($this->indexPrice, $this->markPrice, $this->lastPrice);
    }

    public function getMaxPrice(): Price
    {
        return max($this->indexPrice, $this->markPrice, $this->lastPrice);
    }
}
