<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Cache;

use App\Bot\Infrastructure\ByBit\ExchangeService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UpdateTickerHandler
{
    public function __construct(private readonly ExchangeService $exchangeService)
    {
    }

    public function __invoke(UpdateTicker $command): void
    {
        $this->exchangeService->updateTicker($command->symbol, $command->ttl);
    }
}
