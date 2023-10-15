<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

use function print_r;

#[AsEventListener]
final class WorkerExceptionEventListener
{
    public function __construct()
    {

    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $exception = $event->getThrowable();

        while ($exception instanceof HandlerFailedException) {
            $exception = $exception->getPrevious();
        }

        $this->printError($exception);

        throw $exception;
    }

    protected function printError(Throwable $exception): void
    {
        print_r($exception->getMessage() . PHP_EOL);
    }
}
