<?php

declare(strict_types=1);


namespace App\Domain\Position\ValueObject;

enum Side: string
{
    case Sell = 'sell';
    case Buy = 'buy';

    private const TITLE = [
        self::Sell->value => 'SHORT',
        self::Buy->value => 'LONG',
    ];

    public function getOpposite(): self
    {
        return $this === self::Buy ? self::Sell : self::Buy;
    }

    public function isShort(): bool
    {
        return $this->value === self::Sell->value;
    }

    public function isLong(): bool
    {
        return $this->value === self::Buy->value;
    }

    public function title(): string
    {
        return self::TITLE[$this->value];
    }
}
