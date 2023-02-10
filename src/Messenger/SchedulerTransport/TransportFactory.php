<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function str_starts_with;

/**
 * @codeCoverageIgnore too simple implementation
 */
final class TransportFactory implements TransportFactoryInterface
{
    private Scheduler $scheduler;

    public function __construct(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    /**
     * @param mixed[] $options
     */
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return new Transport($this->scheduler);
    }

    /**
     * @param mixed[] $options
     */
    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'cron://');
    }
}
