<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Exception\Market;

use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Trading\Domain\Symbol\SymbolInterface;
use Exception;

use function sprintf;

final class TickerNotFoundException extends Exception
{
    public static function forSymbolAndCategory(SymbolInterface $symbol, AssetCategory $category): self
    {
        return new self(
            sprintf('%s %s ticker not found', $category->value, $symbol->name())
        );
    }
}
