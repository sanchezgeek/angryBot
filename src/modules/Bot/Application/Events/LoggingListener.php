<?php

declare(strict_types=1);

namespace App\Bot\Application\Events;

use App\Bot\Application\Events\Exchange\TickerUpdated;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class LoggingListener
{
    use LoggerTrait;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function __invoke(TickerUpdated $event): void
    {
        $this->info($event->getLog());
    }
}
