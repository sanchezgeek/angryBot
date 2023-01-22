<?php

namespace App\Delivery\Domain;

use App\Delivery\Domain\Exception\OrderDeliveryAlreadyExists;
use App\EventBus\EventBus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Delivery>
 *
 * @method Delivery|null find($id, $lockMode = null, $lockVersion = null)
 * @method Delivery|null findOneBy(array $criteria, array $orderBy = null)
 * @method Delivery[]    findAll()
 * @method Delivery[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DeliveryRepository extends ServiceEntityRepository
{
    public function __construct(
        private readonly EventBus $eventBus,
        ManagerRegistry $registry,
    ) {
        parent::__construct($registry, Delivery::class);
    }

    /**
     * @throws OrderDeliveryAlreadyExists
     */
    public function save(Delivery $delivery): void
    {
        try {
            $isTransactionActive = $this->getEntityManager()->getConnection()->isTransactionActive();

            $save = function () use ($delivery) {
                $this->getEntityManager()->persist($delivery);
                $this->eventBus->handleEvents($delivery);
            };

            $isTransactionActive ? $save() : $this->getEntityManager()->wrapInTransaction($save);
        } catch (UniqueConstraintViolationException) {
            $delivery = $this->findOneByOrderId($delivery->getOrderId());
            throw OrderDeliveryAlreadyExists::withDeliveryId($delivery->getId());
        }
    }

    public function findOneByOrderId(int $orderId): ?Delivery
    {
        return $this->findOneBy(['orderId' => $orderId]);
    }

    public function getNextId(): int
    {
        return $this->_em->getConnection()->executeQuery('SELECT nextval(\'delivery_id_seq\')')->fetchOne();
    }
}
