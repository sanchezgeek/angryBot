<?php

declare(strict_types=1);

namespace App\Trading\Application\Order\ContextShortcut\Processor;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Order\OrderType;

final class ByMarketContextShortcutProcessor extends AbstractShortcutContextProcessor
{
    private const string KNOWN_CONTEXT = 'bM';

    public function supports(
        string $shortcut,
        OrderType $orderType
    ): bool {
        return $shortcut === self::KNOWN_CONTEXT && $orderType === OrderType::Stop;
    }

    protected function rawContextPart(string $shortcut, OrderType $orderType): array
    {
        return [Stop::CLOSE_BY_MARKET_CONTEXT => true];
    }

    public function doModifyOrder(string $shortcut, BuyOrder|Stop $order): void
    {
        $order->setIsCloseByMarketContext();
    }
}
