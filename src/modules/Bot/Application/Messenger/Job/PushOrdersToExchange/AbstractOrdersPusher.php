<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\PushOrdersToExchange;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;

use Throwable;

use function end;
use function explode;
use function get_class;
use function sleep;

abstract class AbstractOrdersPusher
{
    use LoggerTrait;

    private const SLEEP_INC = 5;
    protected int $lastSleep = 0;

    /**
     * @var PositionData[]
     */
    private array $positionsData = [];

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

    protected function logExchangeClientException(Throwable $exception): void
    {
        $class = explode('\\', get_class($exception));

        $this->warning(sprintf('%s received', end($class)));
    }

    private function fakePosition(Side $side, Symbol $symbol): Position
    {
        $entryPrice = $this->exchangeService->ticker($symbol)->indexPrice;

        return new Position($side, $symbol, $entryPrice, 0, 0, 0, 0, 0);
    }
}
