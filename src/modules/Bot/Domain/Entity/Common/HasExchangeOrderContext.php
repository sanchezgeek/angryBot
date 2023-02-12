<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity\Common;

trait HasExchangeOrderContext
{
    public function setExchangeOrderId(string $exchangeOrderId): void
    {
        $this->context['exchange.orderId'] = $exchangeOrderId;
    }

    public function getExchangeOrderId(): ?string
    {
        return $this->context['exchange.orderId'] ?? null;
    }
}
