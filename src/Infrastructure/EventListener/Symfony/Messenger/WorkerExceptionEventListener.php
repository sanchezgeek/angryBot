<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener\Symfony\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

use function print_r;
use function sprintf;
use function str_contains;

#[AsEventListener]
final readonly class WorkerExceptionEventListener
{
    public function __construct(private LoggerInterface $appErrorLogger)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $error = $event->getThrowable();
        while ($error instanceof HandlerFailedException) {
            $error = $error->getPrevious();
        }

        $this->logAppError($error);
        $this->printError($error);
    }

    private function printError(Throwable $error): void
    {
        print_r(sprintf('%s::%s', $error->getFile() , $error->getLine()) . PHP_EOL);
        print_r($error->getMessage() . PHP_EOL);
    }

    private function logAppError(\Throwable $error): void
    {
        if (str_contains($error->getMessage(), 'timestamp or recv_window param')) {
            return;
        }

        $exception = $error;
        while ($exception->getPrevious()) {
            $exception = $exception->getPrevious();
            if ($exception instanceof TransportExceptionInterface) {
                return;
            }
        }

        $this->appErrorLogger->critical(
            $error->getMessage(),
            [
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString(),
                'previous' => $error->getPrevious(),
            ],
        );
    }
}
