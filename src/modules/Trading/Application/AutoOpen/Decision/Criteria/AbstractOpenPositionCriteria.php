<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

use JsonSerializable;
use Stringable;

abstract class AbstractOpenPositionCriteria implements JsonSerializable, Stringable
{
    abstract public static function getAlias(): string;

    public function jsonSerialize(): mixed
    {
        return [
            'alias' => static::getAlias(),
        ];
    }

    public function __toString(): string
    {
        return json_encode($this);
    }
}
