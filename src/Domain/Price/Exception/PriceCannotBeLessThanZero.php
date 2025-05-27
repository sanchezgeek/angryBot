<?php

declare(strict_types=1);

namespace App\Domain\Price\Exception;

use Exception;

final class PriceCannotBeLessThanZero extends Exception
{
    public function __construct(float $price, $symbol)
    {
        parent::__construct(
            sprintf('Price cannot be less than zero (%s %s)', $price, $symbol->value)
        );
    }
}
