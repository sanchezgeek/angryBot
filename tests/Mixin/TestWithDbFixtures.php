<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use App\Tests\Fixture\AbstractFixture;
use Doctrine\ORM\EntityManagerInterface;

trait TestWithDbFixtures
{
    use TestWithDoctrineRepository;

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
}
