<?php

declare(strict_types=1);

namespace App\Trading\Application\Order\ContextShortcut\Processor;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Order\OrderType;

final class WithoutOppositeOrderContextShortcutProcessor extends AbstractShortcutContextProcessor
{
    private const string KNOWN_CONTEXT = 'wOO';

    public function supports(string $shortcut, BuyOrder|Stop|OrderType $orderType): bool
    {
        return $shortcut === self::KNOWN_CONTEXT;
    }

    protected function rawContextPart(string $shortcut, BuyOrder|Stop|OrderType $order): array
    {
        return [Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT => true];
    }

    public function doModifyOrder(string $shortcut, BuyOrder|Stop $order): void
    {
        $order->setIsWithoutOppositeOrder();
    }
}
