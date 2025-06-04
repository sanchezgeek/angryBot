<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Utils;

use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

final class FixupOrdersDoubling
{
    public function __construct(
        public readonly SymbolInterface $symbol,
        public readonly OrderType $orderType,
        public readonly Side $positionSide,
        public readonly int $step,
        public readonly int $maxStepOrdersQnt,
        public readonly bool $groupInOne = false
    ) {
    }
}
