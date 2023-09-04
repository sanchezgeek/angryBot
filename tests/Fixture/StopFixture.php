<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\Stop;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;

final class StopFixture extends AbstractDoctrineFixture
{
    public function __construct(private readonly Stop $stop)
    {
    }

    protected function getEntity(): Stop
    {
        return $this->stop;
    }

    public function apply(ContainerInterface $container): void
    {
        parent::apply($container);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        /**
         * @see StopService
         */
        $entityManager->getConnection()->executeQuery('SELECT setval(\'stop_id_seq\', (SELECT MAX(id) + 1 from stop), false);');
    }
}
