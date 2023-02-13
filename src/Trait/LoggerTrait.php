<?php

declare(strict_types=1);

namespace App\Trait;

use Psr\Log\LoggerInterface;

trait LoggerTrait
{
    protected ?LoggerInterface $logger;

    protected  function info(string $message, array $context = []): void
    {
        \print_r($message . PHP_EOL);
        $this->logger->info($message, $context);
    }

    protected function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }
}
