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
    public function calculateTestCasesProvider(): iterable
    {
        $firstGrade = [new DeliveryRange(100, 0, 100), new DeliveryRange(80, 100, 300), new DeliveryRange(70, 300, null)];

        yield '305 | (0..100 => 100, 100..300 => 80, 300 ................ => 70) === 26350' => [
            $firstGrade,
            305,
            26350,
        ];

        yield '299 | (0..100 => 100, 100..300 => 80, 300 ................ => 70) === 25920' => [
            $firstGrade,
            299,
            25920,
        ];

//        yield '305 | 0..8 => 100, 8..11, 11..12, 12 ................' => [
//            [new DeliveryRange(, 0, 8), new DeliveryRange(80, 100, 300), new DeliveryRange(70, 300, null)],
//            305,
//            26350,
//        ];
    }

    /**
     * @dataProvider calculateTestCasesProvider
     */
    public function testCalculate(array $ranges, int $distance, int $expectedCost): void
    {
        $calculator = new  DeliveryCostCalculator();

        $cost = $calculator->calculate($distance, ...$ranges);

        self::assertSame($expectedCost, $cost);
    }
}


/*
Необходимо рассчитать стоимость перевозки груза, чем дальше расстояние тем меньше оплата за км.
НАПРИМЕР: от 1 до 100 км стоимость 100р за километр от 100 до 300 км стоимость 80р за километр от 300 и выше км стоимость 70р за километр
Пользователь хочет узнать стоимость перевозки, например за 305 км,
    получается 100км за каждый,
    следующие 200 по 80р за каждый,
    и еще 5 по 70р за каждый
В итоге мы должны получить  26 350р
Необходимо реализовать данный калькулятор на php.
Данный калькулятор должен решать задачу с любыми входящими данные (разные цены, километражи)
Задачу выполнить в стиле ООП.
Вышеприведённые данные указаны как ПРИМЕР. */
