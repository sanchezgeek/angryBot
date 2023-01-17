<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use Closure;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\DataFixtures\ContainerAwareLoader;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class Fixtures
{
    private Closure | ContainerInterface $container;

    /**
     * @var array<string, AbstractFixture>
     */
    private array $fixtures = [];

    private ?ORMExecutor $executor = null;

    public function __construct(Closure | ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function add(AbstractFixture $fixture): self
    {
        $class = $fixture::class;

        if (isset($this->fixtures[$class])) {
            throw new InvalidArgumentException(\sprintf('The %s fixture has already been added.', $class));
        }

        $this->fixtures[$class] = $fixture;

        return $this;
    }

    public function apply(): void
    {
        $executor = $this->getExecutor();
        $loader = new ContainerAwareLoader($this->getContainer());

        foreach ($this->fixtures as $fixture) {
            $loader->addFixture($fixture);
        }

        $executor->execute($loader->getFixtures(), true);
    }

    public function clear(): void
    {
        $this->getExecutor()->purge();
    }

    private function getContainer(): ContainerInterface
    {
        if ($this->container instanceof Closure) {
            $this->container = ($this->container)();
        }

        return $this->container;
    }

    private function getExecutor(): ORMExecutor
    {
        if (!$this->executor) {
            /** @var EntityManagerInterface $entityManager */
            $entityManager = $this->getContainer()->get(EntityManagerInterface::class);

            $purger = new ORMPurger($entityManager);
            $purger->setPurgeMode(ORMPurger::PURGE_MODE_TRUNCATE);

            $this->executor = new ORMExecutor($entityManager, $purger);
        }

        return $this->executor;
    }
}
