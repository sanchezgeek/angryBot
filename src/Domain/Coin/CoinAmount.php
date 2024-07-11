<?php

declare(strict_types=1);

namespace App\Domain\Coin;

use App\Domain\Value\Common\AbstractFloat;
use JsonSerializable;
use Stringable;

use function round;

final class CoinAmount extends AbstractFloat implements JsonSerializable, Stringable
{
    public function __construct(private readonly Coin $coin, float $amount)
    {
        parent::__construct($amount);
    }

    public function coin(): Coin
    {
        return $this->coin;
    }

    public function value(): float
    {
        $precision = $this->coin->coinCostPrecision();

        return round(parent::value(), $precision);
    }

    public function jsonSerialize(): mixed
    {
        return $this->value();
    }

    public function __toString(): string
    {
        return (string)$this->value();
    }
}
