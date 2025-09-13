<?php

declare(strict_types=1);

namespace App\Bot\Application\Events;

use App\Bot\Application\Events\Exchange\PositionUpdated;
use App\Bot\Application\Events\Exchange\TickerUpdated;
use App\Bot\Application\Events\Exchange\TickerUpdateSkipped;
use App\Clock\ClockInterface;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class LogLoggableEventListener implements EventSubscriberInterface
{
    use LoggerTrait;

    public function __construct(
        ClockInterface $clock,
        LoggerInterface $logger,
        private readonly RateLimiterFactory $tickerUpdatedLogThrottlingLimiter,
    ) {
        $this->clock = $clock;
        $this->logger = $logger;
    }

    public function __invoke(LoggableEvent $event): void
    {
        if (($log = $event->getLog()) === null) {
            return;
        }

        $message = $log;

        if ($event instanceof TickerUpdated || $event instanceof TickerUpdateSkipped) {
            $symbol = $event instanceof TickerUpdated ? $event->ticker->symbol : $event->foundCachedTickerDto->ticker->symbol;
            if (!$this->tickerUpdatedLogThrottlingLimiter->create($symbol->name())->consume()->isAccepted()) {
                return;
            }
        }

        $this->info($message, $event->getContext());
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TickerUpdated::class => '__invoke',
            TickerUpdateSkipped::class => '__invoke',
            PositionUpdated::class => '__invoke',
//            BuyOrderPushedToExchange::class => '__invoke',,
//            ActiveCondStopMovedBack::class => '__invoke',
        ];
    }
}
