<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity\Common;

trait HasOriginalPriceContext
{
    public function getOriginalPrice(): ?float
    {
        return $this->context['originalPrice'] ?? null;
    }

    private function setOriginalPrice(float $price): void
    {
        // Do not replace existed one
        if (!isset($this->context['originalPrice'])) {
            $this->context['originalPrice'] = $price;
        }
    }
}
