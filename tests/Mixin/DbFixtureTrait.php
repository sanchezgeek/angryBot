<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use App\Tests\Fixture\AbstractFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;

trait DbFixtureTrait
{
    /**
     * @var AbstractFixture[]
     */
    private array $appliedFixtures = [];

    protected function beginFixturesTransaction(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        if ($entityManager->getConnection()->isTransactionActive()) {
            throw new \RuntimeException('There is an active transaction already.');
        }

        $entityManager->getConnection()->beginTransaction();
    }

    protected function applyDbFixtures(AbstractFixture ...$fixtures): void
    {
        /** @var ContainerInterface $container */
        $container = static::getContainer();

        foreach ($fixtures as $fixture) {
            $fixture->apply($container);
            $this->appliedFixtures[] = $fixture;
        }
    }

    /**
     * @after
     */
    protected function clear(): void
    {
        if ($this->appliedFixtures) {
            /** @var ContainerInterface $container */
            $container = static::getContainer();

            foreach ($this->appliedFixtures as $fixture) {
                $fixture->clear($container);
            }

            $this->appliedFixtures = [];
        }
    }

    protected static function truncateTable(string $table, string $schema = 'public'): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(\sprintf('TRUNCATE TABLE "%s"."%s" RESTART IDENTITY CASCADE', $schema, $table));
    }
}
