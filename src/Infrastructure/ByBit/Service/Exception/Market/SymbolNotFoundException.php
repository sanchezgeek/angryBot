<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Exception\Market;

use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Trading\Domain\Symbol\SymbolInterface;
use Exception;

use function sprintf;

final class SymbolNotFoundException extends Exception
{
    public static function forSymbolAndCategory(string $name, AssetCategory $category): self
    {
        return new self(
            sprintf('%s %s symbol not found', $category->value, $name)
        );
    }
}
