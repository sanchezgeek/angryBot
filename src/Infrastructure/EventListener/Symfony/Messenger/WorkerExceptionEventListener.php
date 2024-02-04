<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener\Symfony\Messenger;

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
    }

    protected function printError(Throwable $exception): void
    {
        var_dump($exception->getFile());
        var_dump($exception->getLine());
        print_r($exception->getMessage() . PHP_EOL);
    }
}
