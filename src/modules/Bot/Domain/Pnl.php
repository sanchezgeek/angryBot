<?php

declare(strict_types=1);

namespace App\Bot\Domain;

final readonly class Pnl implements \Stringable
{
    public function __construct(private float $value, private string $currency = 'USDT')
    {
    }

    public function format(): string
    {
        $sign = $this->value > 0 ? '+' : '-';

        return \sprintf('%s%.3f %s', $sign, \abs($this->value), $this->currency);
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
