<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Cache;

use App\Bot\Application\Service\Exchange\TickersCache;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpdateTickerHandler
{
    public function __construct(private TickersCache $tickersCache)
    {
    }

    public function __invoke(UpdateTicker $command): void
    {
        $this->tickersCache->updateTicker($command->symbol, $command->ttl);
    }
}
