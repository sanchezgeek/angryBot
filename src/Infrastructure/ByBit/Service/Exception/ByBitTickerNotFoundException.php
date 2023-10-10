<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Exception;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use Exception;

use function sprintf;

final class ByBitTickerNotFoundException extends Exception
{
    public static function forSymbolAndCategory(Symbol $symbol, AssetCategory $category): self
    {
        return new self(
            sprintf('%s %s ticker not found', $category->value, $symbol->value)
        );
    }
}
