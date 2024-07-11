<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Service;

use App\Domain\Value\Percent\Percent;

interface ExchangeCommissionProvider
{
    public function getTakerFee(): Percent;
}
