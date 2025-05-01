<?php

declare(strict_types=1);

namespace App\Infrastructure\Logger;

use App\Application\Logger\AppErrorLoggerInterface;
use App\Application\Logger\LoggableExceptionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

readonly class SymfonyAppErrorLogger implements AppErrorLoggerInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $appErrorLogger)
    {
        $this->logger = $appErrorLogger;
    }

    public function exception(Throwable $e): void
    {
        $message = $e->getMessage();

        if ($e instanceof LoggableExceptionInterface) {
            $context = $e->data();
        } else {
            $context = [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'previous' => $e->getPrevious(),
            ];
        }

        $this->logger->critical($message, $context);
    }

    public function error(string $message, array $data = []): void
    {
        $this->logger->error($message, $data);
    }
}
