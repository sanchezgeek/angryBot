<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity\Common;

trait HasWithoutOppositeContext
{
    public const WITHOUT_OPPOSITE_ORDER_CONTEXT = 'withoutOppositeOrder';

    public function isWithOppositeOrder(): bool
    {
        $withoutOppositeOrder = ($this->context[self::WITHOUT_OPPOSITE_ORDER_CONTEXT] ?? false) === true;

        return !$withoutOppositeOrder;
    }
}
