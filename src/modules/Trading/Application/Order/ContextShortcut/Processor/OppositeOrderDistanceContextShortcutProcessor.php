<?php

declare(strict_types=1);

namespace App\Trading\Application\Order\ContextShortcut\Processor;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Domain\Stop\Helper\PnlHelper;
use InvalidArgumentException;
use RuntimeException;

final class OppositeOrderDistanceContextShortcutProcessor extends AbstractShortcutContextProcessor
{
    private const string KNOWN_CONTEXT = 'o';

    public function supports(string $shortcut, BuyOrder|Stop|OrderType $orderType): bool
    {
        return (bool)preg_match('/^' . self::KNOWN_CONTEXT . '=\d+(?:%)?$/', $shortcut);
    }

    private function parseDistance(string $shortcut, BuyOrder|Stop|OrderType $order): float
    {
        if ($order instanceof OrderType) {
            throw new RuntimeException('Applicable only for concrete order');
        }

        $providedValue = explode('=', $shortcut)[1];

        try {
            $pnlValue = $this->fetchPercentValue($providedValue);
            $basedOnPrice = $this->exchangeService->ticker($order->getSymbol())->indexPrice;

            $distance = PnlHelper::convertPnlPercentOnPriceToAbsDelta($pnlValue, $basedOnPrice);
        } catch (InvalidArgumentException) {
            $distance = (float)$providedValue;
        }

        return $distance;
    }
    protected function rawContextPart(string $shortcut, BuyOrder|Stop|OrderType $order): array
    {
        return [BuyOrder::OPPOSITE_ORDERS_DISTANCE_CONTEXT => $this->parseDistance($shortcut, $order)];
    }

    public function doModifyOrder(string $shortcut, BuyOrder|Stop $order): void
    {
        $order->setOppositeOrdersDistance(
            $this->parseDistance($shortcut, $order)
        );
    }

    private function fetchPercentValue(string $value): float
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

    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService
    ) {
    }
}
