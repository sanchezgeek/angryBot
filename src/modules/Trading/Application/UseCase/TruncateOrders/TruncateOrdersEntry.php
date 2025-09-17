<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\TruncateOrders;

use App\Trading\Application\UseCase\TruncateOrders\Enum\TruncateOrdersMode;
use App\Trading\Application\UseCase\TruncateOrders\Enum\TruncateOrdersType;
use InvalidArgumentException;

final class TruncateOrdersEntry
{
    public const string AFTER_SL_CALLBACK_ALIAS = 'after-sl';

    public const string ADDITIONAL_SL_FROM_LIQUIDATION_HANDLER_CALLBACK_ALIAS = 'additional-from-liquidation-handler';
    public const string LOCK_IN_PROFIT_FIXATION_CALLBACK_ALIAS = 'lock-in-profit-fixation-sl';

    private const array PREDEFINED_BUY_ORDERS_FILTER_CALLBACKS = [
        self::AFTER_SL_CALLBACK_ALIAS => 'isAdditionalStopFromLiquidationHandler()',
    ];

    private const array PREDEFINED_STOPS_FILTER_CALLBACKS = [
        self::ADDITIONAL_SL_FROM_LIQUIDATION_HANDLER_CALLBACK_ALIAS => 'isAdditionalStopFromLiquidationHandler()',
        self::LOCK_IN_PROFIT_FIXATION_CALLBACK_ALIAS => 'createdAsLockInProfit()',
    ];

    private array $buyOrdersFilterCallbacks = [];
    private array $stopsFilterCallbacks = [];

    public bool $dry = false;

    public function __construct(
        public readonly TruncateOrdersMode $mode,
        public readonly TruncateOrdersType $ordersType,
    ) {
    }

    public function setDryRun(): self
    {
        $this->dry = true;

        return $this;
    }

    public function addBuyOrdersFilterCallback(string $filterCallback): self
    {
        $this->buyOrdersFilterCallbacks[] = $filterCallback;

        return $this;
    }

    public function addStopsFilterCallback(string $filterCallback): self
    {
        $this->stopsFilterCallbacks[] = $filterCallback;

        return $this;
    }

    public function addPredefinedBuyOrderFilterCallback(string $callbackAlias): self
    {
        if (!isset(self::PREDEFINED_BUY_ORDERS_FILTER_CALLBACKS[$callbackAlias])) {
            throw new InvalidArgumentException(sprintf('Cannot find BuyOrders callback by "%s" alias', $callbackAlias));
        }

        return $this->addBuyOrdersFilterCallback(self::PREDEFINED_BUY_ORDERS_FILTER_CALLBACKS[$callbackAlias]);
    }

    public function addPredefinedStopsFilterCallback(string $callbackAlias): self
    {
        if (!isset(self::PREDEFINED_STOPS_FILTER_CALLBACKS[$callbackAlias])) {
            throw new InvalidArgumentException(sprintf('Cannot find Stops callback by "%s" alias', $callbackAlias));
        }

        return $this->addStopsFilterCallback(self::PREDEFINED_STOPS_FILTER_CALLBACKS[$callbackAlias]);
    }

    public function getBuyOrdersFilterCallbacks(): array
    {
        return array_map('trim', $this->buyOrdersFilterCallbacks);
    }

    public function getStopsFilterCallbacks(): array
    {
        return array_map('trim', $this->stopsFilterCallbacks);
    }
}
