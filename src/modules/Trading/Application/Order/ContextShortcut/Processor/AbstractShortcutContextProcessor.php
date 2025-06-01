<?php

declare(strict_types=1);

namespace App\Trading\Application\Order\ContextShortcut\Processor;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Helper\OutputHelper;
use App\Trading\Application\Order\ContextShortcut\Exception\UnapplicableContextShortcutProcessorException;

abstract class AbstractShortcutContextProcessor implements ShortcutContextProcessorInterface
{
    /**
     * @throws UnapplicableContextShortcutProcessorException
     */
    final protected function checkIsApplicable(string $shortcut, OrderType|Stop|BuyOrder $orderType): void
    {
        $orderType = self::getOrderType($orderType);

        if (!$this->supports($shortcut, $orderType)) {
            throw new UnapplicableContextShortcutProcessorException(
                sprintf('%s is unapplicable for process "%s" with OrderType="%s"', OutputHelper::shortClassName(static::class), $shortcut, $orderType->value)
            );
        }
    }

    final public function getRawContextPart(string $shortcut, BuyOrder|Stop|OrderType $order): array
    {
        $this->checkIsApplicable($shortcut, $order);

        return $this->rawContextPart($shortcut, $order);
    }

    final public function modifyRawContextArray(string $shortcut, BuyOrder|Stop|OrderType $order, array &$contextRaw): void
    {
        $contextRaw = array_merge($contextRaw, $this->getRawContextPart($shortcut, $order));
    }

    public function modifyOrder(string $shortcut, BuyOrder|Stop $order): void
    {
        $this->checkIsApplicable($shortcut, $order);

        $this->doModifyOrder($shortcut, $order);
    }

    abstract protected function rawContextPart(string $shortcut, BuyOrder|Stop $order);
    abstract protected function doModifyOrder(string $shortcut, BuyOrder|Stop $order);

    protected static function getOrderType(BuyOrder|Stop|OrderType $orderType): OrderType
    {
        return $orderType instanceof OrderType ? $orderType : OrderType::fromEntity($orderType);
    }
}
