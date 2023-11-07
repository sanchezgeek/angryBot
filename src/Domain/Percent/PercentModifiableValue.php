<?php

declare(strict_types=1);

namespace App\Domain\Percent;

use App\Domain\Percent\ValueObject\Percent;

interface PercentModifiableValue
{
    public function addPercent(Percent $percent): IntegerValue|AbstractFloatValue;
}
