<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Bot\Domain\Entity\Stop;
use App\Domain\Position\ValueObject\Side;

final class StopFixture extends AbstractDoctrineFixture
{
    private const DEFAULT_VOLUME = 0.001;
    private const DEFAULT_TRIGGER_DELTA = 10;

    private int $id;
    private float $price;
    private float $volume;
    private float $triggerDelta;

    private function __construct(private Side $positionSide)
    {
    }

    public static function short(int $id, float $price, float $volume = self::DEFAULT_VOLUME): self
    {
        $fixture = new self(Side::Sell);

        $fixture->id = $id;
        $fixture->price = $price;
        $fixture->volume = $volume;

        $fixture->triggerDelta = self::DEFAULT_TRIGGER_DELTA;

        return $fixture;
    }

    public function withTriggerDelta(float $triggerDelta): self
    {
        $this->triggerDelta = $triggerDelta;

        return $this;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getVolume(): float
    {
        return $this->volume;
    }

    protected function buildEntity(): Stop
    {
        return new Stop($this->id, $this->price, $this->volume, $this->triggerDelta, $this->positionSide);
    }
}
