<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Domain\Price\Price;

class PriceTestHelper
{
    public static function middleBetween(Price $one, Price $another): Price
    {
        return Price::float($one->add($another)->value() / 2, $one->precision);
    }
}