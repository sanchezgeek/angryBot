<?php

declare(strict_types=1);

namespace App\Bot\Application\Events;

use App\Bot\Application\Events\Exchange\TickerUpdated;
use App\Clock\ClockInterface;
use App\Helper\RunningContext;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class LoggingListener
{
    use LoggerTrait;

    public function __construct(
        ClockInterface $clock,
        LoggerInterface $logger
    ) {
        $this->clock = $clock;
        $this->logger = $logger;
    }

    public function __invoke(TickerUpdated $event): void
    {
        $this->info(
            \sprintf('%s [%s]', $event->getLog(), RunningContext::getRunningWorker()),
        );
    }
}
