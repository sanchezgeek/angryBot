<?php

declare(strict_types=1);

namespace App\Trading\Application\Order\ContextShortcut;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Trading\Application\Order\ContextShortcut\Exception\UnapplicableContextShortcutProcessorException;
use App\Trading\Application\Order\ContextShortcut\Processor\ShortcutContextProcessorInterface;
use RuntimeException;

final class ContextShortcutRootProcessor implements ShortcutContextProcessorInterface
{
    /** @var ShortcutContextProcessorInterface[] */
    private array $processors = [];

    public function __construct(iterable $processors)
    {
        foreach ($processors as $processor) {
            if (!$processor instanceof ShortcutContextProcessorInterface) {
                throw new RuntimeException(
                    sprintf('Processor must implement %s interface. Object of "%s" type given', ShortcutContextProcessorInterface::class, gettype($processor))
                );
            }

            $this->processors[] = $processor;
        }
    }

    /**
     * @throws UnapplicableContextShortcutProcessorException
     */
    public function getResultContextArray(array $shortcuts, OrderType $orderType): array
    {
        $result = [];
        foreach ($shortcuts as $shortcut) {
            $this->modifyRawContextArray($shortcut, $orderType, $result);
        }

        return $result;
    }

    /**
     * @throws UnapplicableContextShortcutProcessorException
     */
    public function modifyOrderWithShortcuts(array $shortcuts, BuyOrder|Stop|OrderType $order): array
    {
        $result = [];
        foreach ($shortcuts as $shortcut) {
            $this->modifyOrder($shortcut, $order);
        }

        return $result;
    }

    public function getRawContextPart(string $shortcut, BuyOrder|Stop|OrderType $order): array
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($shortcut, $order)) {
                return $processor->getRawContextPart($shortcut, $order);
            }
        }

        self::throwUnapplicableException($shortcut, $order);
    }

    public function modifyRawContextArray(string $shortcut, BuyOrder|Stop|OrderType $order, array &$contextRaw): void
    {
        $modified = false;
        foreach ($this->processors as $processor) {
            if ($processor->supports($shortcut, $order)) {
                $processor->modifyRawContextArray($shortcut, $order, $contextRaw);
                $modified = true;
            }
        }

        if (!$modified) {
            self::throwUnapplicableException($shortcut, $order);
        }
    }

    public function modifyOrder(string $shortcut, BuyOrder|Stop $order): void
    {
        $modified = false;

        $orderType = OrderType::fromEntity($order);
        foreach ($this->processors as $processor) {
            if ($processor->supports($shortcut, $orderType)) {
                $processor->modifyOrder($shortcut, $order);
                $modified = true;
            }
        }

        if (!$modified) {
            self::throwUnapplicableException($shortcut, $orderType);
        }
    }

    public function supports(string $shortcut, OrderType|BuyOrder|Stop $orderType): bool
    {
        return true;
    }

    private static function throwUnapplicableException(string $shortcut, BuyOrder|Stop|OrderType $orderType): void
    {
        $orderType = $orderType instanceof OrderType ? $orderType : OrderType::fromEntity($orderType);

        throw new RuntimeException(
            sprintf('Cannot find appropriate processor for process given "%s" context for OrderType=%s', $shortcut, $orderType->value)
        );
    }
}
