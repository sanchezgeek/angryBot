<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Dto;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

readonly class SandboxStopOrder
{
    public function __construct(public Symbol $symbol, public Side $positionSide, public float $price, public float $volume)
    {
    }

    public static function fromStop(Stop $stop): self
    {
        return new self($stop->getSymbol(), $stop->getPositionSide(), $stop->getPrice(), $stop->getVolume());
    }
}