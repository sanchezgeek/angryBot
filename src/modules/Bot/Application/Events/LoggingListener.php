<?php

declare(strict_types=1);

namespace App\Bot\Application\Events;

use App\Bot\Application\Events\Exchange\TickerUpdated;
use App\Clock\ClockInterface;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class LoggingListener
{
    use LoggerTrait;

    public function __construct(private readonly ClockInterface $clock, LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function __invoke(TickerUpdated $event): void
    {
        $now = $this->clock->now()->format('m/d H:i:s');

        $this->info(
            \sprintf('%s | %s', $now, $event->getLog()),
        );
    }
}
