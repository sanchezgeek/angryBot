<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\EventListener\Messenger\WorkerMessageFailedEvent;

use App\Clock\ClockInterface;
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
use function str_contains;

#[AsEventListener]
final class LogWorkerExceptionEventListener
{
    private const CONNECTION_ERR_MESSAGES = [
        'timestamp or recv_window param',
        'Server Timeout',
        'Idle timeout reached',
//        'Timestamp for this request is outside of the recvWindow',
    ];

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
        # connection errors
        $exception = $error;
        while (($previous = $exception->getPrevious()) && ($previous instanceof TransportExceptionInterface)) {
            $exception = $previous;
        }
        if ($exception instanceof TransportExceptionInterface) {
            $this->logConnectionError($exception);
            return;
        }

        foreach (self::CONNECTION_ERR_MESSAGES as $expectedMessage) {
            if (str_contains($error->getMessage(), $expectedMessage)) {
                $this->logConnectionError($error);
                return;
            }
        }

        # app errors
        $this->appErrorLogger->critical($error->getMessage(), [
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
            'previous' => $error->getPrevious(),
        ]);
    }

    private const CONN_ERR_PENDING_INTERVAL = 3;
    private const CONN_ERR_RESET_INTERVAL = 35;

    private ?int $connErrorRecievedAt = null;
    private ?int $resetAt = null;

    private function logConnectionError(Throwable $error): void
    {
        $now = $this->clock->now()->getTimestamp();

        if ($this->resetAt && $now >= $this->resetAt) {
            $this->connErrorRecievedAt = null;
            $this->resetAt = null;
            return;
        }

        if ($this->connErrorRecievedAt === null) {
            $this->connErrorRecievedAt = $now;
            $this->resetAt = $now + self::CONN_ERR_RESET_INTERVAL;
            return;
        }

        $this->resetAt = $now + self::CONN_ERR_RESET_INTERVAL;

        $pendingTill = $this->connErrorRecievedAt + self::CONN_ERR_PENDING_INTERVAL;
        if ($now <= $pendingTill) {
            return;
        }

        if ($this->connectionErrorsLogThrottlingLimiter->consume()->isAccepted()) {
            $this->connectionErrorLogger->critical($error->getMessage(), [
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'previous' => $error->getPrevious(),
            ]);
        }
    }
}
