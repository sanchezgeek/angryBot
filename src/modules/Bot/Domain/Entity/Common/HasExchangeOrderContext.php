<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity\Common;

trait HasExchangeOrderContext
{
    public const EXCHANGE_ORDER_ID_CONTEXT = 'exchange.orderId';

    public function setExchangeOrderId(string $exchangeOrderId): self
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

    public function clearExchangeOrderId(): self
    {
        if (isset($this->context[self::EXCHANGE_ORDER_ID_CONTEXT])) {
            unset($this->context[self::EXCHANGE_ORDER_ID_CONTEXT]);
        }

        return $this;
    }
}
