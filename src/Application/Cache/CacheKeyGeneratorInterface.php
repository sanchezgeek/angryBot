<?php

declare(strict_types=1);

namespace App\Application\Cache;

interface CacheKeyGeneratorInterface
{
    public function generate(): string;
}
