<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;

final readonly class MarketBuyCheckDto implements CheckOrderDto
{
    private float $executionPrice;

    public function __construct(
        public MarketBuyEntryDto $inner,
        Ticker $ticker,
    ) {
        $this->executionPrice = $ticker->lastPrice->value();
    }

    public function symbol(): Symbol
    {
        return $this->inner->symbol;
    }

    public function positionSide(): Side
    {
        return $this->inner->positionSide;
    }

    public function priceValueWillBeingUsedAtExecution(): float
    {
        return $this->executionPrice;
    }

    public function orderQty(): float
    {
        return $this->inner->volume;
    }

    public function orderIdentifier(): ?string
    {
        return $this->inner->sourceBuyOrder ? sprintf('BuyOrder.id=%d', $this->inner->sourceBuyOrder->getId()) : null;
    }
}
