<?php

namespace App\Application;

use function uniqid;

final class UniqueIdGenerator implements UniqueIdGeneratorInterface
{
    public function generateUniqueId(string $prefix): string
    {
        return uniqid($prefix, true);
    }
}
