<?php

declare(strict_types=1);

namespace App\Screener\Application\Contract\Dto;

use App\Domain\Price\SymbolPrice;
use App\Domain\Value\Percent\Percent;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateTimeImmutable;

final class PriceChangeInfo
{
    public function __construct(
        public SymbolInterface $symbol,
        public DateTimeImmutable $fromDate,
        public SymbolPrice $fromPrice,
        public DateTimeImmutable $toDate,
        public SymbolPrice $toPrice,
        public float $partOfDayPassed,
//        public Percent $pricePercentChangeConsideredAsSignificant,
    ) {
    }

    public function getPriceChangePercent(): Percent
    {
        $delta = $this->absPriceDelta();
        $fromPrice = $this->fromPrice->value();

        return Percent::fromPart($delta / $fromPrice, false);
    }

    public function absPriceDelta(): float
    {
        return $this->toPrice->value() - $this->fromPrice->value();
    }
}
