<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use Doctrine\Common\DataFixtures\AbstractFixture as BaseFixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;

abstract class AbstractFixture extends BaseFixture
{
    final public function load(ObjectManager $manager): void
    {
        // Doesn't support MongoDB and other NOSQL
        \assert($manager instanceof EntityManagerInterface);

        $this->apply($manager);
    }

    abstract protected function apply(EntityManagerInterface $em): void;
}
