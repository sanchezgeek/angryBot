<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Cache;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class WarmupTickerHandler
{
    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService
    ) {
    }

    public function __invoke(WarmupTicker $command): void
    {
        $this->exchangeService->ticker($command->symbol);
    }
}
