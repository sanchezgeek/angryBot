<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Cache;

use App\Infrastructure\Cache\TickersCache;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpdateTickerHandler
{
    public function __construct(private TickersCache $tickersCache)
    {
    }

    public function __invoke(UpdateTicker $command): void
    {
        foreach ($command->symbols as $symbol) {
            $this->tickersCache->checkExternalTickerCacheOrUpdate($symbol, $command->ttl);
        }
    }
}
