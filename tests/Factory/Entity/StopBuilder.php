<?php

declare(strict_types=1);

namespace App\Tests\Factory\Entity;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

final class StopBuilder
{
    private Side $side;
    private SymbolInterface $symbol;
    private int $id;
    private float $price;
    private float $volume;
    private ?float $triggerDelta = null;
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
        return new Stop($this->id, $this->price, $this->volume, $this->triggerDelta, $this->symbol, $this->side, $this->context);
    }
}
