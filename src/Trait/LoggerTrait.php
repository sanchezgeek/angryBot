<?php

declare(strict_types=1);

namespace App\Trait;

use App\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

use Throwable;

use function end;
use function explode;
use function get_class;
use function sprintf;

trait LoggerTrait
{
    private CONST DT_FORMAT = 'm/d H:i:s';

    protected ?LoggerInterface $logger;
    protected ?ClockInterface $clock;

    protected  function info(string $message, array $context = []): void
    {
        $this->print(sprintf('%s', $message));
//        $this->logger->info($message, $context);
    }

    protected function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    protected function critical(string $message, array $context = []): void
    {
        $this->logger->critical($message, $context);
    }

    protected function print(string $message): void
    {
        $dateTime = $this->clock->now()->format(self::DT_FORMAT);
        print_r(sprintf('%s | %s', $dateTime, $message) . PHP_EOL);
    }

    private function exceptionShortName(Throwable $exception): string
    {
        $class = explode('\\', get_class($exception));
        return end($class);
    }
}
