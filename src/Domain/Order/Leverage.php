<?php

declare(strict_types=1);

namespace App\Domain\Order;

use DomainException;

use function sprintf;

final class Leverage
{
    private int $value;

    public function __construct(int $value)
    {
        if ($value <= 1 || $value > 100) {
            throw new DomainException(sprintf('Leverage value must be in 1..100 range. "%d" given.', $value));
        }

        $this->value = $value;
    }

    public function value(): int
    {
        return $this->value;
    }
}
