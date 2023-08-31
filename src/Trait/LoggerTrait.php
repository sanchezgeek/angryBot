<?php

declare(strict_types=1);

namespace App\Trait;

use App\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

trait LoggerTrait
{
    private CONST DT_FORMAT = 'm/d H:i:s';

    protected ?LoggerInterface $logger;
    protected ?ClockInterface $clock;

    protected  function info(string $message, array $context = []): void
    {
        $now = $this->clock->now()->format(self::DT_FORMAT);

        \print_r(\sprintf('%s | %s', $now, $message) . PHP_EOL);
        $this->logger->info($message, $context);
    }

    protected function warning(string $message, array $context = []): void
    {
        $now = $this->clock->now()->format(self::DT_FORMAT);

        \print_r(\sprintf('%s | ! %s', $now, $message) . PHP_EOL);
        $this->logger->warning($message, $context);
    }
}
