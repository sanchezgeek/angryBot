<?php

declare(strict_types=1);

namespace App\Tests\Factory\Entity;

use App\Bot\Domain\Entity\Stop;
use App\Domain\Position\ValueObject\Side;

final class StopBuilder
{
    private Side $side;
    private int $id;
    private float $price;
    private float $volume;
    private ?float $triggerDelta = null;
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

    public function withTD(float $triggerDelta): self
    {
        $builder = clone $this;
        $builder->triggerDelta = $triggerDelta;

        return $builder;
    }

    public function withContext(array $context): self
    {
        $builder = clone $this;
        $builder->context = $context;

        return $builder;
    }

    public function build(): Stop
    {
        return new Stop($this->id, $this->price, $this->volume, $this->triggerDelta, $this->side, $this->context);
    }
}
