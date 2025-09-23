<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Trading\Applicaiton\AutoOpen\Decision\Criteria;

use App\Trading\Application\AutoOpen\Decision\Criteria\InstrumentAgeIsAppropriateCriteriaHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers InstrumentAgeIsAppropriateCriteriaHandler
 */
final class InstrumentAgeIsAppropriateCriteriaHandlerTest extends KernelTestCase
{
    private InstrumentAgeIsAppropriateCriteriaHandler $handler;

    protected function setUp(): void
    {
        $this->handler = self::getContainer()->get(InstrumentAgeIsAppropriateCriteriaHandler::class);
    }

    /**
     * @dataProvider calculateAgeConfidenceCases
     */
    public function testCalculateAgeConfidence(float $daysAge, float $expectedResult): void
    {
        $result = $this->handler->calculateAgeConfidence($daysAge);

        self::assertEquals($expectedResult, $result);
    }

    public static function calculateAgeConfidenceCases(): array
    {
        return [
            [0.5, 0.085],
            [1, 0.17],
            [2, 0.21],
            [3, 0.25],
            [4, 0.267],
            [5, 0.283],
            [6, 0.3],
            [7, 0.35],
            [8, 0.4],
            [9, 0.45],
            [10, 0.5],
            [15, 0.625],
            [20, 0.75],
            [25, 0.8],
            [30, 0.85],
            [35, 0.9],
            [40, 0.95],
            [45, 0.955],
            [50, 0.96],
            [60, 0.97],
            [70, 1],
            [80, 1],
            [90, 1],
            [100, 1],
        ];
    }
}
