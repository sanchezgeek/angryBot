<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

trait RateLimiterAwareTest
{
    public static function makeRateLimiterFactory(
        int $limit = 5,
        string $inInterval = '5 seconds',
    ): RateLimiterFactory {
        return new RateLimiterFactory([
            'policy' => 'token_bucket',
            'id' => 'test',
            'limit' => $limit,
            'rate' => [
                'interval' => $inInterval,
            ],
        ], new InMemoryStorage());
    }
}
