<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Position\ValueObject;

use App\Domain\Position\ValueObject\Side;
use PHPUnit\Framework\TestCase;

final class SideTest extends TestCase
{
    public function testLongExpectations(): void
    {
        self::assertTrue(Side::Buy->isLong());
        self::assertFalse(Side::Buy->isShort());
        self::assertSame('buy', Side::Buy->value);
        self::assertEquals(Side::Sell, Side::Buy->getOpposite());
        self::assertEquals('LONG', Side::Buy->title());
    }

    public function testShortExpectations(): void
    {
        self::assertTrue(Side::Sell->isShort());
        self::assertFalse(Side::Sell->isLong());
        self::assertSame('sell', Side::Sell->value);
        self::assertEquals(Side::Buy, Side::Sell->getOpposite());
        self::assertEquals('SHORT', Side::Sell->title());
    }
}
