<?php

declare(strict_types=1);

namespace App\Profiling\Application\Collector;

use App\Profiling\SDK\ProfilingPointDto;

final class ProfilingPointsStaticCollector
{
    /** @var ProfilingPointDto[] */
    static array $points = [];

    public static function addPoint(ProfilingPointDto $point): void
    {
        self::$points[] = $point;
    }

    public static function releasePoints(): array
    {
        $points = self::$points;

        self::$points = [];

        return $points;
    }
}
