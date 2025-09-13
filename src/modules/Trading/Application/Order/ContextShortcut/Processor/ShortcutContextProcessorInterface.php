<?php

declare(strict_types=1);

namespace App\Trading\Application\Order\ContextShortcut\Processor;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Trading\Application\Order\ContextShortcut\Exception\UnapplicableContextShortcutProcessorException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('trading.order.contextShortcutProcessor')]
interface ShortcutContextProcessorInterface
{
    public function supports(string $shortcut, OrderType $orderType): bool;

    /**
     * @throws UnapplicableContextShortcutProcessorException
     */
    public function getRawContextPart(string $shortcut, BuyOrder|Stop $order): array;

    /**
     * @throws UnapplicableContextShortcutProcessorException
     */
    public function modifyRawContextArray(string $shortcut, BuyOrder|Stop|OrderType $order, array &$contextRaw): void;

    /**
     * @throws UnapplicableContextShortcutProcessorException
     */
    public function modifyOrder(string $shortcut, BuyOrder|Stop $order): void;
}
