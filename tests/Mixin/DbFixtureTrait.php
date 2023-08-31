<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use App\Tests\Fixture\AbstractFixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

use RuntimeException;

use function sprintf;

trait DbFixtureTrait
{
    /**
     * @var AbstractFixture[]
     */
    private array $appliedFixtures = [];

    protected static function beginTransaction(): void
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
        foreach ($fixtures as $fixture) {
            $fixture->apply(static::getContainer());
            $this->appliedFixtures[] = $fixture;
        }
    }

    /**
     * @after
     */
    protected function clear(): void
    {
        if ($this->appliedFixtures) {
            foreach ($this->appliedFixtures as $fixture) {
                $fixture->clear(static::getContainer());
            }

            $this->appliedFixtures = [];
        }
    }

    protected static function truncate(string $className): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $repository = self::getRepository($entityManager, $className);

        foreach ($repository->findAll() as $entity) {
            $entityManager->remove($entity);
        }

        $entityManager->flush();
    }

    protected static function ensureTableIsEmpty(string $className): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $repository = self::getRepository($entityManager, $className);

        self::assertEmpty($repository->findAll());
    }

    protected static function getRepository(EntityManagerInterface $entityManager, string $className): EntityRepository
    {
        if (!$entityRepository = $entityManager->getRepository($className)) {
            throw new RuntimeException(sprintf('Repository for %s entity not found', $className));
        }

        return $entityRepository;
    }
}
