<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Dto\In;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use Stringable;

use function sprintf;

readonly class SandboxStopOrder implements Stringable
{
    /**
     * MB private?
     */
    public function __construct(
        public Symbol $symbol,
        public Side $positionSide,
        public float $price,
        public float $volume,
        public ?Stop $sourceOrder = null
    ) {
    }

    public static function fromStop(Stop $stop): self
    {
        return new self($stop->getSymbol(), $stop->getPositionSide(), $stop->getPrice(), $stop->getVolume(), $stop);
    }

    public function desc(): string
    {
        return sprintf('%s %s SL (%s/%s)', $this->symbol->value, $this->positionSide->title(), $this->volume, $this->price);
    }

    public function __toString(): string
    {
        return $this->desc();
    }
}
