<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

use App\Helper\OutputHelper;
use Stringable;

abstract class AbstractOpenPositionCriteria implements Stringable
{
    abstract public function getAlias(): string;

    public function __toString(): string
    {
        return OutputHelper::shortClassName(static::class);
    }
}
