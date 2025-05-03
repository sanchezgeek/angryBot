<?php

declare(strict_types=1);

namespace App\Trading\Application\Check\Contract;

use LogicException;

abstract readonly class AbstractTradingCheckResult
{
    protected function __construct(
        public bool $success,
        public string $source,
        public string $info,
        public ?TradingCheckFailedReason $failedReason = null
    ) {
        if ($this->success && $failedReason) {
            throw new LogicException('$failedReason not allowed when succeed');
        } // elseif (!$this->success && !$failedReason)
    }

    public function info(): string
    {
        return sprintf('%s%s: %s', $this->source, $this->success ? 'SUCCEED' : 'FAILED', $this->info);
    }
}
