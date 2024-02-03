<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Value\Percent;

use App\Domain\Value\Common\AbstractFloat;
use App\Domain\Value\Percent\Percent;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * @covers \App\Domain\Value\Percent\Percent
 */
final class PercentTest extends TestCase
{
    /**
     * @dataProvider invalidPercentValueProvider
     */
    public function testFailCreate(float $value): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            sprintf('Percent value must be in 0..100 range. "%.2f" given.', $value),
        );

        new Percent($value);
    }

    /**
     * @dataProvider invalidPercentValueProvider
     */
    public function testFailCreateFromString(float $value): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            sprintf('Percent value must be in 0..100 range. "%.2f" given.', $value),
        );

        Percent::string($value . '%');
    }

    private function invalidPercentValueProvider(): array
    {
        return [[0], [0.0], [-1], [-1.1], [101], [101.1]];
    }

    /**
     * @dataProvider invalidStringPercentProvider
     */
    public function testFailCreateFromInvalidString(string $str): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf('Invalid percent string provided ("%s").', $str)
        );

        Percent::string($str);
    }

    private function invalidStringPercentProvider(): array
    {
        return [['10'], ['asd'], ['asd10%'], ['10%asd']];
    }

    /**
     * @dataProvider validPercentValueProvider
     */
    public function testCanCreate(float $value, float $expectedPart): void
    {
        $percent = new Percent($value);

        self::assertEquals($value, $percent->value());
        self::assertEquals($expectedPart, $percent->part());
    }

    private function validPercentValueProvider(): array
    {
        return [[1, 0.01], [1.1, 0.011], [10, 0.1], [10.1, 0.101], [99.9, 0.999], [100, 1]];
    }

    /**
     * @dataProvider validStringFactoryCases
     */
    public function testCanCreateFromString(string $str, Percent $expectedPercent): void
    {
        $result = Percent::string($str);

        self::assertEquals($expectedPercent, $result);
    }

    private function validStringFactoryCases(): array
    {
        return [
            ['1%', new Percent(1)],
            ['1.1%', new Percent(1.1)],
            ['10%', new Percent(10)],
            ['10.1%', new Percent(10.1)],
            ['99.9%', new Percent(99.9)],
            ['100%', new Percent(100)],
        ];
    }

    /**
     * @dataProvider partOfScalarValueTestCases
     */
    public function testGetPartOfScalarValue(mixed $value, Percent $percent, mixed $expectedValue): void
    {
        $result = $percent->of($value);

        self::assertEquals($expectedValue, $result);
    }

    private function partOfScalarValueTestCases(): array
    {
        return [
            [10.5, Percent::string('10%'), 1.05],
            [10.5, Percent::string('50%'), 5.25],
            [10, Percent::string('50%'), 5],
        ];
    }

    public function testGetPartOfInheritedAbstractFloat(): void
    {
        $percent = Percent::string('10%');

        $resultFloatObject = $this->createMock(AbstractFloat::class);

        $value = $this->createMock(AbstractFloat::class);
        $value->expects(self::once())->method('getPercentPart')->with($percent)->willReturn($resultFloatObject);

        // Act
        $result = $percent->of($value);

        self::assertEquals($resultFloatObject, $result);
    }
}
