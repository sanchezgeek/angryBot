<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;

use function usort;

trait StopTest
{
    protected static function seeStopsInDb(Stop ...$stops): void
    {
        usort($stops, static fn (Stop $a, Stop $b) => $a->getId() <=> $b->getId());

        self::assertEquals($stops, self::getStopRepository()->findAll());
    }

    protected static function getStopRepository(): StopRepository
    {
        return self::getContainer()->get(StopRepository::class);
    }
}
