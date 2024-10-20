<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener\Symfony\Messenger\ConsoleErrorEvent;

use App\Helper\OutputHelper;
use App\Worker\AppContext;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class PrintConsoleErrorEventListener
{
    public function __invoke(ConsoleErrorEvent $event): void
    {
        // skip if event occurred in workers runtime
        if (AppContext::runningWorker()) {
            return;
        }

        OutputHelper::print($event->getError());
    }
}
