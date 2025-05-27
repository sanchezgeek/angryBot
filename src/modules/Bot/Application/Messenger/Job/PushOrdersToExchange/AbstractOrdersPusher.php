<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Clock\ClockInterface;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;
use Throwable;

use function sleep;
use function sprintf;

abstract class AbstractOrdersPusher
{
    use LoggerTrait;

    private const SLEEP_INC = 5;
    protected int $lastSleep = 0;

    /**
     * @param ByBitLinearExchangeCacheDecoratedService $exchangeService
     * @param ByBitLinearPositionCacheDecoratedService $positionService
     */
    public function __construct(
        protected readonly ExchangeServiceInterface $exchangeService,
        protected readonly PositionServiceInterface $positionService,
        ClockInterface $clock,
        LoggerInterface $logger,
    ) {
        $this->clock = $clock;
        $this->logger = $logger;
    }

    protected function sleep(string $cause): void
    {
        $this->lastSleep += self::SLEEP_INC;

        $this->info(
            sprintf(
                'Sleep for %d seconds, because %s',
                $this->lastSleep,
                $cause,
            ),
        );

        sleep($this->lastSleep);

        if ($this->lastSleep > 15) {
            $this->lastSleep = 0;
        }
    }

    protected function logWarning(Throwable $exception, bool $withOut = true): void
    {
        $message = $this->buildLogMessage($exception);
        $this->warning($message);

        if ($withOut) {
            $this->print(sprintf('@ %s', $message));
        }
    }

    protected function logCritical(Throwable $exception, bool $withLog = true): void
    {
        $message = $this->buildLogMessage($exception);
        $this->print(sprintf('! %s', $message));

        if ($withLog) {
            $this->critical($message, [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'previous' => $exception->getPrevious(),
            ]);
        }
    }

    protected function buildLogMessage(Throwable $exception): string
    {
        return sprintf('%s received ("%s")', $this->exceptionShortName($exception), $exception->getMessage());
    }
}
