<?php

namespace App\Bot\Domain;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Delivery\Domain\Delivery;
use App\EventBus\EventBus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Delivery>
 *
 * @method Stop|null find($id, $lockMode = null, $lockVersion = null)
 * @method Stop|null findOneBy(array $criteria, array $orderBy = null)
 * @method Stop[]    findAll()
 * @method Stop[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StopRepository extends ServiceEntityRepository implements PositionOrderRepository
{
    public function __construct(
        private readonly EventBus $eventBus,
        ManagerRegistry $registry,
    ) {
        parent::__construct($registry, Stop::class);
    }

    public function save(Stop $stop): void
    {
        $isTransactionActive = $this->getEntityManager()->getConnection()->isTransactionActive();

        $save = function () use ($stop) {
            $this->getEntityManager()->persist($stop);
            $this->eventBus->handleEvents($stop);
        };

        $isTransactionActive ? $save() : $this->getEntityManager()->wrapInTransaction($save);
    }

    public function remove(Stop $stop): void
    {
        $this->getEntityManager()->remove($stop);
        $this->getEntityManager()->flush();
    }

    /**
     * @return Stop[]
     */
    public function findActive(Side $side, ?Ticker $ticker = null, callable $qbModifier = null): array
    {
        $qb = $this->createQueryBuilder('s');

        $qb
            ->andWhere('s.positionSide = :posSide')
            ->andWhere("HAS_ELEMENT(s.context, 'exchange.orderId') = false")
            ->andWhere("HAS_ELEMENT(s.context, 'onlyAfterExchangeOrderExecuted') = false")
            ->setParameter(':posSide', $side)
        ;

        if ($ticker) {
            if ($side === Side::Sell) {
                $qb->andWhere('s.price < :price');
                $qb->setParameter(':price', $ticker->indexPrice + 50);
            } else {
                $qb->andWhere('s.price > :price');
                $qb->setParameter(':price', $ticker->indexPrice - 50);
            }
        }

        if ($qbModifier) {
            $qbModifier($qb);
        }

        return $qb->getQuery()->getResult();
    }

    public function getNextId(): int
    {
        return $this->_em->getConnection()->executeQuery('SELECT nextval(\'stop_id_seq\')')->fetchOne();
    }
}
