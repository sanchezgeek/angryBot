<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;

use function array_map;
use function usort;

trait StopsTester
{
    use TestWithDoctrineRepository;
    use PositionSideAwareTest;
    use TestWithDbFixtures;
    use SymbolsDependentTester;

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

    private function haveStopsInDb(Stop ...$stops): void
    {
        $this->applyDbFixtures(...array_map(static fn(Stop $stop) => new StopFixture($stop), $stops));
    }

    protected static function seeStopsInDb(Stop ...$expectedStops): void
    {
        self::getEntityManager()->clear();
        $actualStops = self::getStopRepository()->findAll();
        self::getEntityManager()->clear();

        usort($expectedStops, static fn (Stop $a, Stop $b) => $a->getId() <=> $b->getId());
        usort($actualStops, static fn (Stop $a, Stop $b) => $a->getId() <=> $b->getId());

        self::assertOrdersEqual($expectedStops, $actualStops);
    }

    protected static function getStopRepository(): StopRepository
    {
        return self::getContainer()->get(StopRepository::class);
    }

    /**
     * @before
     */
    protected static function truncateStops(): int
    {
        $qnt = self::truncate(Stop::class);

        $entityManager = self::getEntityManager();
        $entityManager->getConnection()->executeQuery('SELECT setval(\'stop_id_seq\', 1, false);');

        return $qnt;
    }
}
