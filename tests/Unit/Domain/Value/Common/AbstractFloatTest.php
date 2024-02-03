<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Value\Common;

use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Domain\Value\Common\AbstractFloat;
use App\Domain\Value\Percent\Percent;
use LogicException;
use PHPUnit\Framework\TestCase;

use function get_class;
use function sprintf;

/**
 * @covers \App\Domain\Value\Common\AbstractFloat
 */
final class AbstractFloatTest extends TestCase
{
    /**
     * @dataProvider inheritedFloatValuesProvider
     */
    public function testFailAdditionWithIncompatibleTypes(AbstractFloat $initialFloatValue): void
    {
        $subtractValue = $this->getMockForAbstractClass(AbstractFloat::class, [0.000003]);
        $initialClass = get_class($initialFloatValue);
        $subtractClass = get_class($subtractValue);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            sprintf('%s: subtracted value must be instance of %s (%s given)', AbstractFloat::class, $initialClass, $subtractClass)
        );

        // Assert
        self::assertNotEquals($initialClass, $subtractClass);

        // Act
        $initialFloatValue->add($subtractValue);
    }

    /**
     * @dataProvider inheritedFloatValuesProvider
     */
    public function testFailSubtractWithIncompatibleTypes(AbstractFloat $initialFloatValue): void
    {
        $subtractValue = $this->getMockForAbstractClass(AbstractFloat::class, [0.000003]);
        $initialClass = get_class($initialFloatValue);
        $subtractClass = get_class($subtractValue);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            sprintf('%s: subtracted value must be instance of %s (%s given)', AbstractFloat::class, $initialClass, $subtractClass)
        );

        self::assertNotEquals($initialClass, $subtractClass);

        $initialFloatValue->sub($subtractValue);
    }

    public function testCanAddScalarFloat(): void
    {
        $initialValue = 0.000015;
        $subtractValue = 0.000003;

        $initialFloatValue = $this->getMockForAbstractClass(AbstractFloat::class, [$initialValue]);

        // Act
        $result = $initialFloatValue->add($subtractValue);

        self::assertEquals($this->getMockForAbstractClass(AbstractFloat::class, [0.000018]), $result);
        self::assertEquals(0.000018, $result->value());
    }

    public function testCanSubtractScalarFloat(): void
    {
        $initialValue = 0.000015;
        $subtractValue = 0.000003;

        $initialFloatValue = $this->getMockForAbstractClass(AbstractFloat::class, [$initialValue]);

        // Act
        $result = $initialFloatValue->sub($subtractValue);

        self::assertEquals($this->getMockForAbstractClass(AbstractFloat::class, [0.000012]), $result);
        self::assertEquals(0.000012, $result->value());
    }

    public function testCanAddPercent(): void
    {
        $initialValue = 0.000015;

        $initialFloatValue = $this->getMockForAbstractClass(AbstractFloat::class, [$initialValue]);

        // Act
        $result = $initialFloatValue->addPercent(Percent::string('50%'));

        self::assertEquals($this->getMockForAbstractClass(AbstractFloat::class, [0.0000225]), $result);
        self::assertEquals(0.0000225, $result->value());
    }

    public function testCanSubPercent(): void
    {
        $initialValue = 0.000015;

        $initialFloatValue = $this->getMockForAbstractClass(AbstractFloat::class, [$initialValue]);

        // Act
        $result = $initialFloatValue->subPercent(Percent::string('10%'));

        self::assertEquals($this->getMockForAbstractClass(AbstractFloat::class, [0.0000135]), $result);
        self::assertEquals(0.0000135, $result->value());
    }

    public function testCanGetPercentPart(): void
    {
        $initialValue = 0.000015;

        $initialFloatValue = $this->getMockForAbstractClass(AbstractFloat::class, [$initialValue]);

        // Act
        $result = $initialFloatValue->getPercentPart(Percent::string('50%'));

        self::assertEquals($this->getMockForAbstractClass(AbstractFloat::class, [0.0000075]), $result);
        self::assertEquals(0.0000075, $result->value());
    }

    private function inheritedFloatValuesProvider(): array
    {
        return [
            [new CoinAmount(Coin::USDT, 100500.1)],
        ];
    }
}
