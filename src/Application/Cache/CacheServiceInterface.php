<?php

declare(strict_types=1);

namespace App\Application\Cache;

interface CacheServiceInterface
{
    public function get(CacheKeyGeneratorInterface $key);
}
