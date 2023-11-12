<?php

declare(strict_types=1);

namespace App\Domain\Value\Percent;

use App\Domain\Value\Common\AbstractFloat;
use App\Domain\Value\Common\IntegerValue;

interface PercentModifiableValue
{
    public function addPercent(Percent $percent): IntegerValue|AbstractFloat;
    public function getPercentPart(Percent $percent): IntegerValue|AbstractFloat;
}
