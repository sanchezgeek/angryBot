<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity\Common;

trait HasExchangeOrderContext
{
    public const string EXCHANGE_ORDER_ID_CONTEXT = 'exchange.orderId';

    /**
     * @internal For use by Stop and BuyOrder | For tests
     */
    public function setExchangeOrderId(string $exchangeOrderId): static
    {
        $this->context[self::EXCHANGE_ORDER_ID_CONTEXT] = $exchangeOrderId;

        return $this;
    }

    public function getExchangeOrderId(): ?string
    {
        return $this->context[self::EXCHANGE_ORDER_ID_CONTEXT] ?? null;
    }

    public function hasExchangeOrderId(): bool
    {
        return isset($this->context[self::EXCHANGE_ORDER_ID_CONTEXT]);
    }

    public function clearExchangeOrderId(): static
    {
        if (isset($this->context[self::EXCHANGE_ORDER_ID_CONTEXT])) {
            unset($this->context[self::EXCHANGE_ORDER_ID_CONTEXT]);
        }

        return $this;
    }
}
