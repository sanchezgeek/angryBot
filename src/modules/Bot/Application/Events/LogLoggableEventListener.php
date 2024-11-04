<?php

declare(strict_types=1);

namespace App\Bot\Application\Events;

use App\Bot\Application\Events\Exchange\PositionUpdated;
use App\Bot\Application\Events\Exchange\TickerUpdated;
use App\Bot\Application\Events\Exchange\TickerUpdateSkipped;
use App\Bot\Application\Events\Stop\ActiveCondStopMovedBack;
use App\Clock\ClockInterface;
use App\Trait\LoggerTrait;
use App\Worker\AppContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function sprintf;

final class LogLoggableEventListener implements EventSubscriberInterface
{
    use LoggerTrait;

    public function __construct(
        ClockInterface $clock,
        LoggerInterface $logger
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
        if ($workerHash = AppContext::workerHash()) {
            $message .= sprintf(' [%s]', $workerHash);
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
            ActiveCondStopMovedBack::class => '__invoke',
        ];
    }
}
