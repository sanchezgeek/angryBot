<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Domain\Dto\Ath;

use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use JsonSerializable;
use Stringable;

final class PricePartOfAth implements JsonSerializable, Stringable
{
    private function __construct(
        public Percent $percent,
        public PricePartOfAthDesc $desc
    ) {
    }

    public static function inTheBetween(float $part): self
    {
        if ($part > 1) {
            OutputHelper::warning('inconsistent: ins case of `inTheBetween` $part must not be greater than 1?');
        }

        return new self(Percent::fromPart($part, false), PricePartOfAthDesc::InBetween);
    }

    public static function overHigh(float $part): self
    {
        if ($part < 1) {
            OutputHelper::warning('inconsistent: ins case of `overHigh` $part must be greater than 1?');
        }

        return new self(Percent::fromPart($part, false), PricePartOfAthDesc::MovedOverHigh);
    }

    public static function overLow(float $part): self
    {
        return new self(Percent::fromPart($part, false), PricePartOfAthDesc::MovedOverLow);
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
