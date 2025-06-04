<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\Stop;
use App\Trading\Domain\Symbol\Entity\Symbol;
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
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        $this->getEntity()->replaceSymbolEntity(
            $entityManager->find(Symbol::class, $this->getEntity()->getSymbol()->name())
        );

        parent::apply($container);

        /**
         * @see StopService
         */
        $entityManager->getConnection()->executeQuery('SELECT setval(\'stop_id_seq\', (SELECT MAX(id) + 1 from stop), false);');
    }
}
