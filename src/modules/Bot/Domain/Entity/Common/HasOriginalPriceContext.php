<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity\Common;

trait HasOriginalPriceContext
{
    public function getOriginalPrice(): ?float
    {
        return $this->context['originalPrice'] ?? null;
    }

    public function setOriginalPrice(float $price): self
    {
        // Do not replace existed one
        if (!isset($this->context['originalPrice'])) {
            $this->context['originalPrice'] = $price;
        }

        return $this;
    }

    public function cleanOriginalPrice(): self
    {
        if (isset($this->context['originalPrice'])) {
            unset($this->context['originalPrice']);
        }

        return $this;
    }
}
