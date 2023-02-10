<?php

namespace App\Bot\Domain;

use App\Bot\Domain\Entity\BuyOrder;
use App\Delivery\Domain\Delivery;
use App\EventBus\EventBus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Delivery>
 *
 * @method BuyOrder|null find($id, $lockMode = null, $lockVersion = null)
 * @method BuyOrder|null findOneBy(array $criteria, array $orderBy = null)
 * @method BuyOrder[]    findAll()
 * @method BuyOrder[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BuyOrderRepository extends ServiceEntityRepository
{
    public function __construct(
        private readonly EventBus $eventBus,
        ManagerRegistry $registry,
    ) {
        parent::__construct($registry, BuyOrder::class);
    }

    public function save(BuyOrder $stop): void
    {
        $isTransactionActive = $this->getEntityManager()->getConnection()->isTransactionActive();

        $save = function () use ($stop) {
            $this->getEntityManager()->persist($stop);
            $this->eventBus->handleEvents($stop);
        };

        $isTransactionActive ? $save() : $this->getEntityManager()->wrapInTransaction($save);
    }

    public function remove(BuyOrder $order): void
    {
        $this->getEntityManager()->remove($order);
        $this->getEntityManager()->flush();
    }

    /**
     * @return BuyOrder[]
     */
    public function findActiveByPosition(Position $position): array
    {
        $qb = $this->createQueryBuilder('s');

        $qb
            ->andWhere('s.positionSide = :posSide')
            ->andWhere("HAS_NOT_ELEMENT(s.context, 'buyOrderId') = true")
            ->setParameter(':posSide', $position->side)
        ;

        return $qb->getQuery()->getResult();
    }

    public function getNextId(): int
    {
        return $this->_em->getConnection()->executeQuery('SELECT nextval(\'buy_order_id_seq\')')->fetchOne();
    }
}
