<?php

declare(strict_types=1);

namespace App\Bot\Application\Events;

use App\Bot\Application\Events\Exchange\PositionUpdated;
use App\Clock\ClockInterface;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class PositionListener
{
    use LoggerTrait;

    public function __construct(ClockInterface $clock, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->clock = $clock;
    }

    public function __invoke(PositionUpdated $event): void
    {
        $this->info($event->getLog());
    }
}
