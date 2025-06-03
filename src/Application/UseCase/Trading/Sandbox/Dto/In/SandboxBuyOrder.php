<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Dto\In;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Order\Contract\OrderTypeAwareInterface;
use App\Domain\Order\Contract\VolumeSignAwareInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use Stringable;

use function sprintf;

readonly class SandboxBuyOrder implements Stringable, VolumeSignAwareInterface, OrderTypeAwareInterface
{
    public function __construct(
        public SymbolInterface $symbol,
        public Side $positionSide,
        public float $price,
        public float $volume,
        public ?BuyOrder $sourceOrder = null
    ) {
    }

    public static function fromBuyOrder(BuyOrder $buyOrder, SymbolPrice|float|null $withPrice = null): self
    {
        $withPrice = $withPrice === null ? $buyOrder->getPrice() : SymbolPrice::toFloat($withPrice);

        return new self($buyOrder->getSymbol(), $buyOrder->getPositionSide(), $withPrice, $buyOrder->getVolume(), $buyOrder);
    }

    public static function fromMarketBuyEntryDto(MarketBuyEntryDto $marketBuyDto, SymbolPrice $price): self
    {
        return new self($marketBuyDto->symbol, $marketBuyDto->positionSide, SymbolPrice::toFloat($price), $marketBuyDto->volume, $marketBuyDto?->sourceBuyOrder);
    }

    public function desc(): string
    {
        return sprintf('%s %s BUY (%s/%s)', $this->symbol->value, $this->positionSide->title(), $this->volume, $this->price);
    }

    public function signedVolume(): float
    {
        return $this->volume;
    }

    public function getOrderType(): OrderType
    {
        return OrderType::Add;
    }

    public function __toString(): string
    {
        return $this->desc();
    }
}
