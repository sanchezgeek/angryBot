<?php

declare(strict_types=1);

namespace App\Domain\Coin;

use App\Domain\Value\Common\AbstractFloat;
use JsonSerializable;
use Stringable;

use function round;
use function sprintf;

final class CoinAmount extends AbstractFloat implements JsonSerializable, Stringable
{
    private int $outputDecimalsOffset = 5;
    private ?int $floatPrecision = null;
    private bool $signed = false;

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
        $precision = $this->floatPrecision ?? $this->coin->coinCostPrecision();

        return round(parent::value(), $precision);
    }

    public function jsonSerialize(): mixed
    {
        return $this->value();
    }

    public function setFloatPrecision(int $precision): self
    {
        $this->floatPrecision = $precision;

        return $this;
    }

    public function setSigned(bool $signed): self
    {
        $this->signed = $signed;

        return $this;
    }

    /**
     * @todo | Move to AbstractFloat?
     */
    public function __toString(): string
    {
        $value = $this->value();

        $precision = $this->floatPrecision ?? $this->coin->coinCostPrecision();
        $outputDecimals = $precision + $this->outputDecimalsOffset;

        if ($this->signed) {
            $sign = match (true) {
                $value > 0 => '+',
                $value < 0 => '',
                default => ' '
            };
            $formatted = $sign . sprintf('%.' . $precision . 'f', $value);

            return sprintf('% ' . $outputDecimals . 's', $formatted);
        }

        return sprintf('% ' . $outputDecimals . '.' . $precision . 'f', $value);
    }

    public function getWholeLength(): int
    {
        $precision = $this->floatPrecision ?? $this->coin->coinCostPrecision();
        $outputDecimals = $precision + $this->outputDecimalsOffset;

        if ($this->signed) {
            return $outputDecimals + $precision + 1;
        } else {
            return $outputDecimals + 1;
        }
    }
}
