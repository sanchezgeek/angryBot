<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class TransportFactory implements TransportFactoryInterface
{
    private Scheduler $scheduler;

    public function __construct(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return new Transport($this->scheduler);
    }

    public function supports(string $dsn, array $options): bool
    {
        return \str_starts_with($dsn, 'cron://');
    }
}
