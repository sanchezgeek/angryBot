<?php

declare(strict_types=1);

namespace App\Tests\Factory\Entity;

use App\Bot\Domain\Entity\BuyOrder;
use App\Domain\Position\ValueObject\Side;

final class BuyOrderBuilder
{
    private Side $side;
    private int $id;
    private float $price;
    private float $volume;
    private float $triggerDelta = 1;
    private array $context = [];

    public function __construct(Side $positionSide, int $id, float $price, float $volume)
    {
        $this->side = $positionSide;
        $this->id = $id;
        $this->price = $price;
        $this->volume = $volume;
    }

    public static function short(int $id, float $price, float $volume): self
    {
        return new self(Side::Sell, $id, $price, $volume);
    }

    public static function long(int $id, float $price, float $volume): self
    {
        return new self(Side::Buy, $id, $price, $volume);
    }

    public function build(): BuyOrder
    {
        return new BuyOrder($this->id, $this->price, $this->volume, $this->side, $this->context);
    }
}
