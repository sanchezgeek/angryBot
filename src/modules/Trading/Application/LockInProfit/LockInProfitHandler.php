<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit;

use App\Application\Logger\AppErrorLoggerInterface;
use App\Helper\OutputHelper;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitStrategyProcessorInterface;
use App\Trading\Contract\LockInProfit\LockInProfitEntry;
use App\Trading\Contract\LockInProfit\LockInProfitHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class LockInProfitHandler implements LockInProfitHandlerInterface
{
    /**
     * @param iterable<LockInProfitStrategyProcessorInterface> $processors
     */
    public function __construct(
        #[AutowireIterator('trading.lockInProfit.strategyProcessor')]
        private iterable $processors,
        private AppErrorLoggerInterface $appErrorLogger,
    ) {
    }

    public function handle(LockInProfitEntry $entry): void
    {
        $processed = false;
        foreach ($this->processors as $processor) {
            if (!$processor->supports($entry)) {
                continue;
            }

            $processor->process($entry);
            $processed = true;
        }

        if (!$processed) {
            $this->appErrorLogger->error(sprintf('Cannot get any processor for entry of %s class ', OutputHelper::shortClassName($entry->innerStrategyDto)));
        }
    }
}
