<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Dto\In;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Contract\OrderTypeAwareInterface;
use App\Domain\Order\Contract\VolumeSignAwareInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use Stringable;

use function sprintf;

readonly class SandboxStopOrder implements Stringable, VolumeSignAwareInterface, OrderTypeAwareInterface
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

    public static function fromStop(Stop $stop, SymbolPrice|float|null $withPrice = null): self
    {
        $withPrice = $withPrice === null ? $stop->getPrice() : SymbolPrice::toFloat($withPrice);

        return new self($stop->getSymbol(), $stop->getPositionSide(), $withPrice, $stop->getVolume(), $stop);
    }

    public function desc(): string
    {
        return sprintf('%s %s SL (%s/%s)', $this->symbol->value, $this->positionSide->title(), $this->volume, $this->price);
    }

    public function signedVolume(): float
    {
        return -$this->volume;
    }

    public function getOrderType(): OrderType
    {
        return OrderType::Stop;
    }

    public function __toString(): string
    {
        return $this->desc();
    }
}
