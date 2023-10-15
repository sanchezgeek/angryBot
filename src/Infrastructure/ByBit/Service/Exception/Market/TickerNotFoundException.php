<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Exception\Market;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use Exception;

use function sprintf;

final class TickerNotFoundException extends Exception
{
    public static function forSymbolAndCategory(Symbol $symbol, AssetCategory $category): self
    {
        return new self(
            sprintf('%s %s ticker not found', $category->value, $symbol->value)
        );
    }
}
