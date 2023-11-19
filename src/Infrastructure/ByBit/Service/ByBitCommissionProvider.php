<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service;

use App\Domain\Exchange\Service\ExchangeCommissionProvider;
use App\Domain\Value\Percent\Percent;

final class ByBitCommissionProvider implements ExchangeCommissionProvider
{
    private const DEFAULT_COMMISSION_PERCENT = '11%';

    private Percent $execOrderCommission;

    public function __construct(string $orderExecCommissionPercent = self::DEFAULT_COMMISSION_PERCENT)
    {
        $this->execOrderCommission = Percent::string($orderExecCommissionPercent);
    }

    public function getExecOrderCommission(): Percent
    {
        return $this->execOrderCommission;
    }
}


