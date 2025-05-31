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
        $orderType = $orderType instanceof OrderType ? $orderType : OrderType::fromEntity($orderType);

        if (!$this->supports($shortcut, $orderType)) {
            throw new UnapplicableContextShortcutProcessorException(
                sprintf('%s is unapplicable for process "%s" with OrderType="%s"', OutputHelper::shortClassName(static::class), $shortcut, $orderType->value)
            );
        }
    }

    final public function getRawContextPart(string $shortcut, OrderType $orderType): array
    {
        $this->checkIsApplicable($shortcut, $orderType);

        return $this->rawContextPart($shortcut, $orderType);
    }

    final public function modifyRawContextArray(string $shortcut, OrderType $orderType, array &$contextRaw): void
    {
        $contextRaw = array_merge($contextRaw, $this->getRawContextPart($shortcut, $orderType));
    }

    public function modifyOrder(string $shortcut, BuyOrder|Stop $order): void
    {
        $this->checkIsApplicable($shortcut,$order);

        $this->doModifyOrder($shortcut, $order);
    }

    abstract protected function rawContextPart(string $shortcut, OrderType $orderType);
    abstract protected function doModifyOrder(string $shortcut, BuyOrder|Stop $order);
}
