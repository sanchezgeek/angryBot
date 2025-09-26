<?php

declare(strict_types=1);

namespace App\Trading\Application\EventListener;

use App\Screener\Application\Event\SignificantPriceChangeFoundEvent;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use App\Trading\Application\AutoOpen\Handler\AutoOpenHandler;
use App\Trading\Application\AutoOpen\Reason\AutoOpenOnSignificantPriceChangeReason;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class RunAutoOpenPositionOnSignificantPriceChangeListener
{
    public function __invoke(SignificantPriceChangeFoundEvent $event): void
    {
        if (!$event->tryOpenPosition) {
            return;
        }

        $this->handler->handle(
            new InitialPositionAutoOpenClaim(
                $event->info->info->symbol,
                $event->positionSideToPositionLoss(),
                new AutoOpenOnSignificantPriceChangeReason($event->info)
            )
        );
    }

    public function __construct(
        private AutoOpenHandler $handler,
    ) {
    }
}
