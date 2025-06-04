<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;

use function get_class;

abstract class AbstractDoctrineFixture extends AbstractFixture
{
    private ?string $appliedEntityClassName = null;
    private ?int $appliedId = null;

    abstract protected function getEntity(): object;

    public function apply(ContainerInterface $container): void
    {
        $entity = $this->getEntity();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        $entityManager->persist($entity);
        $entityManager->flush();

        $this->appliedEntityClassName = get_class($entity);
        if (method_exists($entity, 'getId')) {
            $this->appliedId = $entity->getId();
        }
    }

    public function clear(ContainerInterface $container): void
    {
        if (!$this->appliedEntityClassName || !$this->appliedId) {
            throw new \LogicException('Fixture must be applied first.');
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        if ($entity = $entityManager->find($this->appliedEntityClassName, $this->appliedId)) {
            $entityManager->remove($entity);
            $entityManager->flush();
        }
    }
}
