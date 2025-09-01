<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit;

use App\Trading\Application\LockInProfit\Strategy\LockInProfitBySteps\LockInProfitByStepsStrategy;
use App\Trading\Contract\LockInProfit\LockInProfitEntry;
use App\Trading\Contract\LockInProfit\LockInProfitHandlerInterface;
use RuntimeException;

final readonly class LockInProfitHandler implements LockInProfitHandlerInterface
{
    public function __construct(
        private LockInProfitByStepsStrategy $defaultStrategy,
    ) {
    }

    public function handle(LockInProfitEntry $entry): void
    {
        if (!$this->defaultStrategy->supports($entry)) {
            throw new RuntimeException(sprintf('Cannot process entry with %s strategy', $entry->strategy->value));
        }

        $this->defaultStrategy->process($entry);
    }
}
