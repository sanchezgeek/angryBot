<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use RuntimeException;

use function count;
use function sprintf;

trait TestWithDoctrineRepository
{
    protected static function truncate(string $className): int
    {
        $entityManager = self::getEntityManager();
        $repository = self::getRepository($className);

        $entities = $repository->findAll();
        foreach ($entities as $entity) {
            $entityManager->remove($entity);
        }
        $entityManager->flush();

        self::ensureTableIsEmpty($className);

        return count($entities);
    }

    protected static function ensureTableIsEmpty(string $className): void
    {
        $repository = self::getRepository($className);

        self::assertEmpty($repository->findAll());
    }

    protected static function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    protected static function getRepository(string $className): EntityRepository
    {
        if (!$entityRepository = self::getEntityManager()->getRepository($className)) {
            throw new RuntimeException(sprintf('Repository for %s entity not found', $className));
        }

        return $entityRepository;
    }
}
