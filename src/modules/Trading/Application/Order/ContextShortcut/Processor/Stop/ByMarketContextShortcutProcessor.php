<?php

declare(strict_types=1);

namespace App\Trading\Application\Order\ContextShortcut\Processor\Stop;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Trading\Application\Order\ContextShortcut\Processor\AbstractShortcutContextProcessor;

final class ByMarketContextShortcutProcessor extends AbstractShortcutContextProcessor
{
    private const string KNOWN_CONTEXT = 'bM';

    public function supports(string $shortcut, BuyOrder|Stop|OrderType $orderType): bool
    {
        return $shortcut === self::KNOWN_CONTEXT && self::isStop($orderType);
    }

    protected function rawContextPart(string $shortcut, BuyOrder|Stop|OrderType $order): array
    {
        return [Stop::CLOSE_BY_MARKET_CONTEXT => true];
    }

    public function doModifyOrder(string $shortcut, BuyOrder|Stop|OrderType $order): void
    {
        $order->setIsCloseByMarketContext();
    }
}
