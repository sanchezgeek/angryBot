<?php

declare(strict_types=1);

namespace App\Domain\Trading\Enum;

/**
 * @see \App\Trading\Application\Parameters\TradingDynamicParameters::stopLength
 * @see \App\Trading\Application\Parameters\TradingDynamicParameters::oppositeBuyLength
 */
enum PriceDistanceSelector: string
{
    case VeryVeryShort = 'very-very-short';
    case VeryShort = 'very-short';
    case Short = 'short';
    case ModerateShort = 'moderate-short';
    case Standard = 'standard';
    case ModerateLong = 'moderate-long';
    case Long = 'long';
    case VeryLong = 'very-long';
    case VeryVeryLong = 'very-very-long';
    case DoubleLong = 'double-long';

    public function toStringWithNegativeSign(): string
    {
        return sprintf('-%s', $this->value);
    }
}
