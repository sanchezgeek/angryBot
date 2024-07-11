<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service;

use App\Domain\Exchange\Service\ExchangeCommissionProvider;
use App\Domain\Value\Percent\Percent;

final class ByBitCommissionProvider implements ExchangeCommissionProvider
{
    private const TAKER_FEE = '0.055%';

    private Percent $execOrderCommission;

    public function __construct(string $orderExecCommissionPercent = self::TAKER_FEE)
    {
        $this->execOrderCommission = Percent::string($orderExecCommissionPercent);
    }

    public function getTakerFee(): Percent
    {
        return $this->execOrderCommission;
    }
}
