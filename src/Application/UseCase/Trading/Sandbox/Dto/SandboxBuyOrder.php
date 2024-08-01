<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Dto;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use Stringable;

use function sprintf;

readonly class SandboxBuyOrder implements Stringable
{
    public function __construct(public Symbol $symbol, public Side $positionSide, public float $price, public float $volume)
    {
    }

    public static function fromBuyOrder(BuyOrder $buyOrder): self
    {
        return new self($buyOrder->getSymbol(), $buyOrder->getPositionSide(), $buyOrder->getPrice(), $buyOrder->getVolume());
    }

    public static function fromMarketBuyEntryDto(MarketBuyEntryDto $marketBuyDto, Price $price): SandboxBuyOrder
    {
        return new SandboxBuyOrder($marketBuyDto->symbol, $marketBuyDto->positionSide, Price::toFloat($price), $marketBuyDto->volume);
    }

    public function desc(): string
    {
        return sprintf('%s %s BUY (%s/%s)', $this->symbol->value, $this->positionSide->title(), $this->volume, $this->price);
    }

    public function __toString(): string
    {
        return $this->desc();
    }
}