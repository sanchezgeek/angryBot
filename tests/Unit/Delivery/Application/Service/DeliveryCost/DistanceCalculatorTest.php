<?php

declare(strict_types=1);

namespace App\Tests\Unit\Delivery\Application\Service\DeliveryCost;

use App\Delivery\Application\Service\Geo\DistanceCalculator;
use App\Delivery\Application\Service\Geo\GeoObject;
use PHPUnit\Framework\TestCase;

final class DistanceCalculatorTest extends TestCase
{
    public function calculateDistanceTestCases(): iterable
    {
        $a = new GeoObject('Россия, Москва, Сумской проезд, 10', 'Россия', 'Москва', 37.608975, 55.634954);
        $b = new GeoObject('Россия, Москва, Бабушкинска, 6', 'Россия', 'Москва', 37.664204, 55.86947);
        $expectedDistance = $this->getDistance(55.634954, 37.608975, 55.86947, 37.664204);

        yield 'from A to B' => [$a, $b, $expectedDistance];
        yield 'from B to A' => [$b, $a, $expectedDistance];

        yield 'from A to A' => [$a, $a, 0];
        yield 'from B to B' => [$b, $b, 0];
    }

    /**
     * @dataProvider calculateDistanceTestCases
     */
    public function testCalculateDistance(GeoObject $source, GeoObject $destination, int $expectedDistance): void
    {
        $distance = (new DistanceCalculator())->getDistanceBetween($source, $destination);

        self::assertEquals($expectedDistance, $distance);
    }

    private function getDistance(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        if ($lat1 === $lat2 && $lon1 === $lon2) {
            return 0;
        } else {
            $theta = $lon1 - $lon2;
            $dist =
                \sin(\deg2rad($lat1)) * \sin(\deg2rad($lat2)) +
                \cos(\deg2rad($lat1)) * \cos(\deg2rad($lat2)) * \cos(\deg2rad($theta));
            $dist = \acos($dist);
            $dist = \rad2deg($dist);
            $miles = $dist * 60 * 1.1515;

            return (int)($miles * 1.609344);
        }
    }
}
