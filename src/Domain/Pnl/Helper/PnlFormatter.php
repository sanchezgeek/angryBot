<?php

declare(strict_types=1);

namespace App\Domain\Pnl\Helper;

use App\Bot\Domain\Pnl;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;

class PnlFormatter
{
    private int $precision;
    private string $currency;
    private bool $showCurrency = true;

    public function __construct(SymbolInterface $symbol)
    {
        $this->precision = $symbol->associatedCoin()->coinCostPrecision();
        $this->currency = $symbol->associatedCoin()->name;
    }

    public static function bySymbol(SymbolInterface $symbol): self
    {
        return new self($symbol);
    }

    public function setPrecision(int $precision): self
    {
        $this->precision = $precision;

        return $this;
    }

    public function setShowCurrency(bool $showCurrency): self
    {
        $this->showCurrency = $showCurrency;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function format(float $value): string
    {
        $sign = $value > 0 ? '+' : '-';

        $format = '%s%.' . $this->precision . 'f';
        $args = [$sign, \abs($value)];

        if ($this->showCurrency) {
            $format .= ' %s';
            $args[] = $this->currency;
        }

        return sprintf($format, ...$args);
    }
}
