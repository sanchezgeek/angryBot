<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Bot\Domain\Entity\BuyOrder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;

final class BuyOrderFixture extends AbstractDoctrineFixture
{
    public function __construct(private readonly BuyOrder $buyOrder)
    {
    }

    protected function getEntity(): BuyOrder
    {
        return $this->buyOrder;
    }

    public function apply(ContainerInterface $container): void
    {
        parent::apply($container);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        /**
         * @see \App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler::handle
         */
        $entityManager->getConnection()->executeQuery('SELECT setval(\'buy_order_id_seq\', (SELECT MAX(id) + 1 from buy_order), false);');
    }
}
