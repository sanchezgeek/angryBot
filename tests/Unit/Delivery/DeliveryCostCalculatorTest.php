<?php

declare(strict_types=1);

namespace App\Tests\Unit\Delivery;

use App\Delivery\DeliveryCostCalculator;
use App\Delivery\DeliveryRange;
use PHPUnit\Framework\TestCase;

final class DeliveryCostCalculatorTest extends TestCase
{
    /**
     * @return iterable<array-key, array<DeliveryRange>, int, int>
     */
    public function calculateTestCases(): iterable
    {
        // (0..100 => 100, 100..300 => 80, 300..∞ => 70)
        $ranges = [new DeliveryRange(0, 100, 100), new DeliveryRange(100, 300, 80), new DeliveryRange(300, null, 70)];

        yield '305km => 26350 rub.' => [
            $ranges,
            305,
            26350,
        ];

        yield '301km => 26070 rub.' => [
            $ranges,
            301,
            26070,
        ];

        yield '300km => 26000 rub.' => [
            $ranges,
            300,
            26000,
        ];

        yield '299km => 25920 rub.' => [
            $ranges,
            299,
            25920,
        ];

        yield '101km => 10080 rub.' => [
            $ranges,
            101,
            10080,
        ];

        yield '100km => 10000 rub.' => [
            $ranges,
            100,
            10000,
        ];

        yield '99km => 9900 rub.' => [
            $ranges,
            99,
            9900,
        ];

        yield '1km => 100 rub.' => [
            $ranges,
            1,
            100,
        ];
    }

    /**
     * @dataProvider calculateTestCases
     */
    public function testCalculate(array $ranges, int $distance, int $expectedCost): void
    {
        $calculator = new DeliveryCostCalculator();

        $cost = $calculator->calculate($distance, ...$ranges);

        self::assertSame($expectedCost, $cost);
    }

    public function testThrowExceptionOnNegativeDistanceCostCalculation()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Distance must be greater than zero.');

        $calculator = new DeliveryCostCalculator();
        $calculator->calculate(-1);
    }

    public function testThrowExceptionOnZeroDistanceCostCalculation()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Distance must be greater than zero.');

        $calculator = new DeliveryCostCalculator();
        $calculator->calculate(0);
    }

    /**
     * @return iterable<array-key, array<DeliveryRange>>
     */
    public function invalidRangesTestCases(): iterable
    {
        yield '0..100, 300..∞ => 100|price gap|300' => [
            [new DeliveryRange(0, 100, 100), new DeliveryRange(300, null, 70)],
        ];

        yield '1..100, 100..∞ => must start from 0' => [
            [new DeliveryRange(1, 100, 100), new DeliveryRange(100, null, 70)],
        ];

        yield '1..100, 100..200 => must end with ∞' => [
            [new DeliveryRange(1, 100, 100), new DeliveryRange(100, 200, 70)],
        ];

        yield '0..100, 100..200 => also invalid' => [
            [new DeliveryRange(0, 100, 100), new DeliveryRange(100, 200, 70)],
        ];
    }

    /**
     * @dataProvider invalidRangesTestCases
     */
    public function testCalculateWithInvalidRangesSpecified(array $ranges): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Check that the segments are specified correctly: maybe the segments intersect or there are gaps between segments.');

        $calculator = new DeliveryCostCalculator();

        $calculator->calculate(305, ...$ranges);
    }
}
