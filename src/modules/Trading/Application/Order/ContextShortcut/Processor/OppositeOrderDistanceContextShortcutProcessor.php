<?php

declare(strict_types=1);

namespace App\Trading\Application\Order\ContextShortcut\Processor;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Domain\Value\Percent\Percent;
use InvalidArgumentException;

final class OppositeOrderDistanceContextShortcutProcessor extends AbstractShortcutContextProcessor
{
    private const string KNOWN_CONTEXT = 'o';

    public function supports(string $shortcut, BuyOrder|Stop|OrderType $orderType): bool
    {
        return (bool)preg_match('/^' . self::KNOWN_CONTEXT . '=[\d\.]+(?:%)?$/', $shortcut);
    }

    protected function rawContextPart(string $shortcut, BuyOrder|Stop|OrderType $order): array
    {
        return [BuyOrder::OPPOSITE_ORDERS_DISTANCE_CONTEXT => (string)$this->parseDistance($shortcut)];
    }
    public function doModifyOrder(string $shortcut, BuyOrder|Stop $order): void
    {
        $order->setOppositeOrdersDistance(
            $this->parseDistance($shortcut)
        );
    }

    private function parseDistance(string $shortcut): float|Percent
    {
        $providedValue = explode('=', $shortcut)[1];

        try {
            $pnlValue = $this->fetchPercent($providedValue);

            return Percent::notStrict($pnlValue);
        } catch (InvalidArgumentException) {
            return (float)$providedValue;
        }
    }

    private function fetchPercent(string $value): float
    {
        if (
            !str_ends_with($value, '%')
            || (!is_numeric(substr($value, 0, -1)))
        ) {
            throw new InvalidArgumentException(
                sprintf('Invalid PERCENT %s provided.', $value)
            );
        }

        return (float)substr($value, 0, -1);
    }
}
