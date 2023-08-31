<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;

use function usort;

trait StopTest
{
    /**
     * @return Stop[]
     */
    protected static function getCurrentStopsSnapshot(): array
    {
        $stops = [];
        foreach(self::getStopRepository()->findAll() as $stop) {
            $stops[] = clone $stop;
        }

        return $stops;
    }

    protected static function seeStopsInDb(Stop ...$expectedStops): void
    {
        $actualStops = self::getStopRepository()->findAll();

        usort($expectedStops, static fn (Stop $a, Stop $b) => $a->getId() <=> $b->getId());
        usort($actualStops, static fn (Stop $a, Stop $b) => $a->getId() <=> $b->getId());

        self::assertEquals($expectedStops, $actualStops);
    }

    protected static function getStopRepository(): StopRepository
    {
        return self::getContainer()->get(StopRepository::class);
    }
}
