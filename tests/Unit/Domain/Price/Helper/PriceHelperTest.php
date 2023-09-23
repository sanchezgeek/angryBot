<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Price\Helper;

use App\Domain\Price\Helper\PriceHelper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Price\Helper\PriceHelper
 */
final class PriceHelperTest extends TestCase
{
    public function testRound(): void
    {
        self::assertEquals(456, PriceHelper::round(455.999));
        self::assertEquals(456, PriceHelper::round(456.001));
        self::assertEquals(456.01, PriceHelper::round(456.009));
        self::assertEquals(456.01, PriceHelper::round(456.01));
        self::assertEquals(456.01, PriceHelper::round(456.011));
        self::assertEquals(456.46, PriceHelper::round(456.456));
        self::assertEquals(456.46, PriceHelper::round(456.4567));
        self::assertEquals(1000, PriceHelper::round(999.999));
    }
}
