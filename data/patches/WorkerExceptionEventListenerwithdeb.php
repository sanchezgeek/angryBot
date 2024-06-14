<?php

declare(strict_types=1);


use App\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class WorkerExceptionEventListenerWithDebug
{
    private const CONNECTION_ERR_MESSAGES = [
        'timestamp or recv_window param',
    ];

    public function __construct(
        private readonly LoggerInterface $appErrorLogger,
        private readonly LoggerInterface $connectionErrorLogger,
        private readonly ClockInterface $clock,
    ) {
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
        foreach (self::CONNECTION_ERR_MESSAGES as $expectedMessage) {
            if (str_contains($error->getMessage(), $expectedMessage)) {
                $this->logConnectionError($error);
                return;
            }
        }

        $exception = $error;
        while ($exception->getPrevious()) {
            $exception = $exception->getPrevious();
            if ($exception instanceof TransportExceptionInterface) {
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

    private const CONN_ERR_PENDING_INTERVAL = 5;
    private const CONN_ERR_RESET_INTERVAL = 25;

    private ?DateTimeImmutable $connErrorRecievedAt = null;
    private ?DateTimeImmutable $resetAt = null;

    private function logConnectionError(Throwable $error): void
    {
        $now = $this->clock->now();

        if ($this->resetAt && $now->getTimestamp() >= $this->resetAt->getTimestamp()) {
            $this->connErrorRecievedAt = null;
            $this->resetAt = null;
            return;
        }

        if ($this->connErrorRecievedAt === null) {
            $this->connErrorRecievedAt = $now;
            $this->resetAt = $now->add(new DateInterval(sprintf('PT%dS', self::CONN_ERR_RESET_INTERVAL)));
            return;
        }

        $this->resetAt = $now->add(new DateInterval(sprintf('PT%dS', self::CONN_ERR_RESET_INTERVAL)));

        $pendingTill = $this->connErrorRecievedAt->add(new DateInterval(sprintf('PT%dS', self::CONN_ERR_PENDING_INTERVAL)));
        $this->debug('now', $now);
        $this->debug('connErrorRecievedAt', $this->connErrorRecievedAt);
        $this->debug('pendingTill', $pendingTill);
        if ($now->getTimestamp() <= $pendingTill->getTimestamp()) {
            return;
        }

        $this->connectionErrorLogger->critical($error->getMessage(), [
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'previous' => $error->getPrevious(),
        ]);
    }

    private function debug(string $msg, DateTimeImmutable $at): void
    {
        var_dump(sprintf('%s %s', $at->format('H:i:s'), $msg));
    }
}
