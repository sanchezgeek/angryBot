<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Traversable;

final class Transport implements TransportInterface
{
    /**
     * @var Scheduler
     */
    private Traversable $scheduler;

    /**
     * @param Scheduler $scheduler
     */
    public function __construct(Traversable $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    /**
     * @return iterable<Envelope>
     */
    public function get(): iterable
    {
        foreach ($this->scheduler as $job) {
            yield new Envelope($job);
        }
    }

    public function ack(Envelope $envelope): void
    {
        // ignore
    }

    public function reject(Envelope $envelope): void
    {
        throw new TransportException('Messages from SchedulerTransport must not be rejected.');
    }

    public function send(Envelope $envelope): Envelope
    {
        throw new InvalidArgumentException('You cannot call send() on the Messenger SchedulerTransport.');
    }
}
