<?php

declare(strict_types=1);

namespace App\Bot\Application\Events;

use App\Bot\Application\Events\BuyOrder\BuyOrderPushedToExchange;
use App\Bot\Application\Events\Exchange\PositionUpdated;
use App\Bot\Application\Events\Exchange\TickerUpdated;
use App\Bot\Application\Events\Stop\ActiveCondStopMovedBack;
use App\Clock\ClockInterface;
use App\Worker\AppContext;
use App\Trait\LoggerTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class LoggingListener implements EventSubscriberInterface
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
        $this->info(
            \sprintf('%s [%s]', $event->getLog(), AppContext::workerHash()),
            $event->getContext(),
        );
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TickerUpdated::class => '__invoke',
//            BuyOrderPushedToExchange::class => '__invoke',
//            PositionUpdated::class => '__invoke',
//            ActiveCondStopMovedBack::class => '__invoke',
        ];
    }
}
