<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Bot\Domain\Entity\BuyOrder;
use App\Trading\Domain\Symbol\Entity\Symbol;
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
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        $this->getEntity()->replaceSymbolEntity(
            $entityManager->find(Symbol::class, $this->getEntity()->getSymbol()->name())
        );

        parent::apply($container);

        /**
         * @see \App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler::handle
         */
        $entityManager->getConnection()->executeQuery('SELECT setval(\'buy_order_id_seq\', (SELECT MAX(id) + 1 from buy_order), false);');
    }
}
