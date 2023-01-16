<?php

declare(strict_types=1);

namespace App\Tests\Unit\Delivery\Application\Service\DeliveryCost;

use App\Delivery\Application\Service\DeliveryCost\DeliveryPriceRange;
use PHPUnit\Framework\TestCase;

/**
 * @see DeliveryPriceRange
 */
final class DeliveryPriceRangeTest extends TestCase
{
    public function testCreateValidRange(): void
    {
        $range = new DeliveryPriceRange(0, 20, 100);

        self::assertSame(0, $range->getStart());
        self::assertSame(20, $range->getEnd());
        self::assertSame(100, $range->getPrice());
    }

    public function invalidCases(): iterable
    {
        yield 'with negative range start' => [-1, 100, 100, 'The beginning of the segment must be greater or equal to zero.'];
        yield 'with negative range end' => [0, -1, 100, 'The end of the segment must be greater than zero.'];
        yield 'with range end greater than start' => [10, 2, 100, 'The end of the segment must be greater than start ("10..2").'];
        yield 'with negative price' => [1, 2, -1, 'The price of the segment ("1..2") must be greater than 0.',];
    }

    /**
     * @dataProvider invalidCases
     */
    public function testCreateInvalidRange(int $start, int $end, int $price, string $expectedException): void
    {
        self::expectExceptionMessage($expectedException);

        new DeliveryPriceRange($start, $end, $price);
    }

    public function appearedCostTestCases(): iterable
    {
        // start, end, price, distance, expectedCost
        yield 'full covered' => [0, 20, 50, 30, 1000];
        yield 'also full covered' => [0, 20, 50, 20, 1000];
        yield 'partially covered' => [0, 20, 50, 15, 750];
        yield 'also partially covered' => [10, 20, 50, 15, 250];
    }

    /**
     * @dataProvider appearedCostTestCases
     */
    public function testGetDistanceCost(int $start, int $end, int $price, int $distance, int $expectedCost): void
    {
        $range = new DeliveryPriceRange($start, $end, $price);

        self::assertSame($expectedCost, $range->getAppearedDistanceCost($distance));
    }
}
