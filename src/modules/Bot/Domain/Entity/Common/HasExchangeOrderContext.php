<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity\Common;

trait HasExchangeOrderContext
{
    public function setExchangeOrderId(string $exchangeOrderId): self
    {
        $this->context['exchange.orderId'] = $exchangeOrderId;

        return $this;
    }

    public function getExchangeOrderId(): ?string
    {
        return $this->context['exchange.orderId'] ?? null;
    }
}
