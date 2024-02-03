<?php

declare(strict_types=1);

namespace App\Domain\Coin;

use App\Domain\Value\Common\AbstractFloat;

use function round;

final class CoinAmount extends AbstractFloat
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
}
