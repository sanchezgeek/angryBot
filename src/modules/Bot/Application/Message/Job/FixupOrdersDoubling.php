<?php

declare(strict_types=1);

namespace App\Bot\Application\Message\Job;

use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Bot\Domain\ValueObject\Position\Side;

final class FixupOrdersDoubling
{
    public function __construct(
        public readonly OrderType $orderType,
        public readonly Side $positionSide,
        public readonly int $step,
        public readonly int $maxStepOrdersQnt,
        public readonly bool $groupInOne = false
    ) {
    }
}
