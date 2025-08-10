<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\AttemptsLimit;

use App\Application\AttemptsLimit\AttemptLimitCheckerInterface;
use App\Application\AttemptsLimit\AttemptLimitCheckerProviderInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

final class SymfonyAttemptLimitCheckerProvider implements AttemptLimitCheckerProviderInterface
{
    /**
     * @var RateLimiterFactory[]
     */
    private array $factories = [];

    public function __construct(
        private readonly CacheItemPoolInterface $cachePool,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function get(string $key, int|string $period, int $attemptsCount = 1): AttemptLimitCheckerInterface
    {
        if (!is_string($period)) {
            $period = sprintf('%d seconds', $period);
        }

        $factoryKey = sprintf('%d_attempts_on_%d_period', $attemptsCount, str_replace(' ', '', $period));
        if (!isset($this->factories[$factoryKey])) {
            $this->factories[$factoryKey] = $this->getLimiterFactory($period, $attemptsCount);
        }

        return new SymfonyAttemptLimitChecker(
            $this->factories[$factoryKey]->create($key)
        );
    }

    public function getLimiterFactory(
        int|string $period,
        int $attemptsCount = 1,
        ?string $serviceId = null,
    ): RateLimiterFactory {
        if (!is_string($period)) {
            $period = sprintf('%d seconds', $period);
        }

        $id = $serviceId ?? uniqid('limiterFactory', true);

        $config = [
            'id' => $id . '_limiterFactory',
            'policy' => 'fixed_window',
            'limit' => $attemptsCount,
            'interval' => $period,
        ];

        return new RateLimiterFactory($config, new CacheStorage($this->cachePool), $this->lockFactory);
    }
}
