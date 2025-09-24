<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Domain\Dto\Ath;

use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use App\TechnicalAnalysis\Domain\Dto\HighLow\HighLowPrices;
use JsonSerializable;
use Stringable;

final class PricePartOfAth implements JsonSerializable, Stringable
{
    private function __construct(
        public Percent $percent,
        public PricePartOfAthDesc $desc,
        public HighLowPrices $source,
    ) {
    }

    public static function inTheBetween(HighLowPrices $source, float $part): self
    {
        if ($part > 1) {
            OutputHelper::warning('inconsistent: ins case of `inTheBetween` $part must not be greater than 1?');
        }

        return new self(Percent::fromPart($part, false), PricePartOfAthDesc::InBetween, $source);
    }

    public static function overHigh(HighLowPrices $source, float $part): self
    {
        if ($part < 1) {
            OutputHelper::warning('inconsistent: ins case of `overHigh` $part must be greater than 1?');
        }

        return new self(Percent::fromPart($part, false), PricePartOfAthDesc::MovedOverHigh, $source);
    }

    public static function overLow(HighLowPrices $source, float $part): self
    {
        return new self(Percent::fromPart($part, false), PricePartOfAthDesc::MovedOverLow, $source);
    }

    public function isPriceMovedOverLow(): bool
    {
        return $this->desc === PricePartOfAthDesc::MovedOverLow;
    }

    public function isPriceMovedOverHigh(): bool
    {
        return $this->desc === PricePartOfAthDesc::MovedOverHigh;
    }

    public function invert(): self
    {
        return new self(
            $this->percent->getComplement(),
            $this->desc->invert(),
            $this->source,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'part' => $this->percent->part(),
            'desc' => $this->desc->value,
        ];
    }

    public function __toString(): string
    {
        return json_encode($this);
    }
}
