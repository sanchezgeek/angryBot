<?php

declare(strict_types=1);

namespace App\Tests\Factory\Entity;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;

final class BuyOrderBuilder
{
    private Side $side;
    private SymbolInterface $symbol;
    private int $id;
    private float $price;
    private float $volume;
    private array $context = [];

    public function __construct(SymbolInterface $symbol, Side $positionSide, int $id, float $price, float $volume)
    {
        $this->side = $positionSide;
        $this->id = $id;
        $this->price = $price;
        $this->volume = $volume;
        $this->symbol = $symbol;
    }

    public static function short(int $id, float $price, float $volume, SymbolInterface $symbol = SymbolEnum::BTCUSDT): self
    {
        return new self($symbol, Side::Sell, $id, $price, $volume);
    }

    public static function long(int $id, float $price, float $volume, SymbolInterface $symbol = SymbolEnum::BTCUSDT): self
    {
        return new self($symbol, Side::Buy, $id, $price, $volume);
    }

    public function build(): BuyOrder
    {
        return new BuyOrder($this->id, $this->price, $this->volume, $this->symbol, $this->side, $this->context);
    }
}
