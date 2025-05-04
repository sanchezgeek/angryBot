<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;

final readonly class StopCheckDto implements CheckOrderDto
{
    private float $executionPrice;

    public function __construct(
        public Stop $inner,
        Ticker $ticker,
    ) {
        $this->executionPrice = $this->inner->isCloseByMarketContextSet() ? $ticker->markPrice->value() : $this->inner->getPrice();
    }

    public function symbol(): Symbol
    {
        return $this->inner->getSymbol();
    }

    public function positionSide(): Side
    {
        return $this->inner->getPositionSide();
    }

    public function priceValueWillBeingUsedAtExecution(): float
    {
        return $this->executionPrice;
    }

    public function orderQty(): float
    {
        return $this->inner->getVolume();
    }

    public function orderIdentifier(): ?string
    {
        return sprintf('Stop.id=%d', $this->inner->getId());
    }
}
