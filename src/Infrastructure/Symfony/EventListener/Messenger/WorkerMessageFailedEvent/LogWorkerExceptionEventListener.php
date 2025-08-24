<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\EventListener\Messenger\WorkerMessageFailedEvent;

use App\Clock\ClockInterface;
use App\Infrastructure\ByBit\API\ConnectionHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

use function print_r;
use function sprintf;

#[AsEventListener]
final class LogWorkerExceptionEventListener
{
    private const int CONN_ERR_PENDING_INTERVAL = 10;
    private const int CONN_ERR_RESET_INTERVAL = 20;

    private ?int $connErrorReceivedAt = null;
    private ?int $resetAt = null;

    private readonly LimiterInterface $connectionErrorsLogThrottlingLimiter;

    public function __construct(
        private readonly LoggerInterface $appErrorLogger,
        private readonly LoggerInterface $connectionErrorLogger,
        private readonly ClockInterface $clock,
        RateLimiterFactory $connectionErrorsLogThrottlingLimiter,
    ) {
        $this->connectionErrorsLogThrottlingLimiter = $connectionErrorsLogThrottlingLimiter->create();
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $error = $event->getThrowable();
        while ($error instanceof HandlerFailedException) {
            $error = $error->getPrevious();
        }

        $this->logError($error);
        $this->printError($error);
    }

    private function printError(Throwable $error): void
    {
        print_r(sprintf('%s::%s', $error->getFile() , $error->getLine()) . PHP_EOL);
        print_r($error->getMessage() . PHP_EOL);
    }

    private function logError(\Throwable $error): void
    {
        if (ConnectionHelper::isConnectionError($error)) {
            $this->logConnectionError($error);
            return;
        }

        $this->appErrorLogger->critical($error->getMessage(), [
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
            'previous' => $error->getPrevious(),
        ]);
    }

    private function logConnectionError(Throwable $error): void
    {
        $now = $this->clock->now()->getTimestamp();

        if ($this->resetAt && $now >= $this->resetAt) {
            $this->connErrorReceivedAt = null;
            $this->resetAt = null;
            return;
        }

        if ($this->connErrorReceivedAt === null) {
            $this->connErrorReceivedAt = $now;
            $this->resetAt = $now + self::CONN_ERR_RESET_INTERVAL;
            return;
        }

        $this->resetAt = $now + self::CONN_ERR_RESET_INTERVAL;

        $pendingTill = $this->connErrorReceivedAt + self::CONN_ERR_PENDING_INTERVAL;
        if ($now <= $pendingTill) {
            return;
        }

        $exception = $error;
        while (($previous = $exception->getPrevious()) && ($previous instanceof TransportExceptionInterface)) {
            $exception = $previous;
        }

        if ($this->connectionErrorsLogThrottlingLimiter->consume()->isAccepted()) {
            $this->connectionErrorLogger->critical($exception->getMessage(), [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'previous' => $exception->getPrevious(),
            ]);
        }
    }
}
