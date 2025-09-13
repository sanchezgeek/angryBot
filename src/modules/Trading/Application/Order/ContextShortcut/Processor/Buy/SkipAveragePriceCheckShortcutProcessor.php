<?php

declare(strict_types=1);

namespace App\Trading\Application\Order\ContextShortcut\Processor\Buy;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Trading\Application\Order\ContextShortcut\Processor\AbstractShortcutContextProcessor;

final class SkipAveragePriceCheckShortcutProcessor extends AbstractShortcutContextProcessor
{
    private const string KNOWN_CONTEXT = 'sAPc';

    public function supports(string $shortcut, BuyOrder|Stop|OrderType $orderType): bool
    {
        return $shortcut === self::KNOWN_CONTEXT && self::isBuy($orderType);
    }

    protected function rawContextPart(string $shortcut, BuyOrder|Stop|OrderType $order): array
    {
        return [
            'checks' => [
                BuyOrder::SKIP_AVERAGE_PRICE_CHECK_KEY => true
            ]
        ];
    }

    public function doModifyOrder(string $shortcut, BuyOrder|Stop $order): void
    {
        $order->setIsWithoutOppositeOrder();
    }
}
