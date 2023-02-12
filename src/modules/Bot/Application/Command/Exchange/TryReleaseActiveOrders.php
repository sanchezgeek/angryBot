<?php

declare(strict_types=1);

namespace App\Bot\Application\Command\Exchange;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Symbol;

readonly final class TryReleaseActiveOrders
{
    public function __construct(
        public Symbol $symbol,
        public ?float $forVolume = null,
        public bool $force = false
    ) {
    }

    public static function forBuyOrder(Symbol $symbol, BuyOrder $order): self
    {
        return new self($symbol, $order->getVolume());
    }

    public static function forStop(Symbol $symbol, Stop $stop): self
    {
        return new self($symbol, $stop->getVolume());
    }
}
