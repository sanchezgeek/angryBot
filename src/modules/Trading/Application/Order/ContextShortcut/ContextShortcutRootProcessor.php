<?php

declare(strict_types=1);

namespace App\Trading\Application\Order\ContextShortcut;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Order\OrderType;
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

    public function getResultContextArray(array $shortcuts, OrderType $orderType): array
    {
        $result = [];
        foreach ($shortcuts as $shortcut) {
            $this->modifyRawContextArray($shortcut, $orderType, $result);
        }

        return $result;
    }

    public function getRawContextPart(string $shortcut, OrderType $orderType): array
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($shortcut, $orderType)) {
                return $processor->getRawContextPart($shortcut, $orderType);
            }
        }

        self::throwUnapplicableException($shortcut, $orderType);
    }

    public function modifyRawContextArray(string $shortcut, OrderType $orderType, array &$contextRaw): void
    {
        $modified = false;
        foreach ($this->processors as $processor) {
            if ($processor->supports($shortcut, $orderType)) {
                $processor->modifyRawContextArray($shortcut, $orderType, $contextRaw);
                $modified = true;
            }
        }

        if (!$modified) {
            self::throwUnapplicableException($shortcut, $orderType);
        }
    }

    public function modifyOrder(string $shortcut, BuyOrder|Stop $order): void
    {
        $modified = false;

        $orderType = OrderType::fromEntity($order);
        foreach ($this->processors as $processor) {
            if ($processor->supports($shortcut, $orderType)) {
                $processor->modifyOrder($shortcut, $order);
            }
        }

        if (!$modified) {
            self::throwUnapplicableException($shortcut, $orderType);
        }
    }

    public function supports(string $shortcut, OrderType $orderType): bool
    {
        return true;
    }

    private static function throwUnapplicableException(string $shortcut, OrderType $orderType): void
    {
        throw new RuntimeException(
            sprintf('Cannot find appropriate processor for process given "%s" context for OrderType=%s', $shortcut, $orderType->value)
        );
    }
}
