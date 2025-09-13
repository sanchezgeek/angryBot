<?php

declare(strict_types=1);

namespace App\Bot\Domain;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class Ticker
{
    public SymbolPrice $markPrice;
    public SymbolPrice $indexPrice;
    public SymbolPrice $lastPrice;

    public function __construct(
        public SymbolInterface $symbol,
        float|SymbolPrice $markPrice,
        float|SymbolPrice $indexPrice,
        float|SymbolPrice $lastPrice,
    ) {
        $this->lastPrice = $lastPrice instanceof SymbolPrice ? $lastPrice : $this->symbol->makePrice($lastPrice);
        $this->markPrice = $markPrice instanceof SymbolPrice ? $markPrice : $this->symbol->makePrice($markPrice);
        $this->indexPrice = $lastPrice instanceof SymbolPrice ? $indexPrice : $this->symbol->makePrice($indexPrice);
    }

    public static function fakeForPrice(SymbolInterface $symbol, SymbolPrice|float $price): self
    {
        return new self($symbol, $price, $price, $price);
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

    public function getMinPrice(): SymbolPrice
    {
        return min($this->indexPrice, $this->markPrice, $this->lastPrice);
    }

    public function getMaxPrice(): SymbolPrice
    {
        return max($this->indexPrice, $this->markPrice, $this->lastPrice);
    }
}
