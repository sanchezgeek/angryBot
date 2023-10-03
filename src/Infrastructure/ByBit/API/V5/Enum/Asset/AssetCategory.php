<?php

namespace App\Infrastructure\ByBit\API\V5\Enum\Asset;

enum AssetCategory: string
{
    case linear = 'linear';
    case inverse = 'inverse';
}
