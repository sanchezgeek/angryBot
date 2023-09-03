<?php

namespace App\Bot\Domain\Repository;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Common\HasExchangeOrderContext;
use App\Bot\Domain\Ticker;
use App\Domain\Position\ValueObject\Side;
use App\EventBus\EventBus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BuyOrder>
 *
 * @method BuyOrder|null find($id, $lockMode = null, $lockVersion = null)
 * @method BuyOrder|null findOneBy(array $criteria, array $orderBy = null)
 * @method BuyOrder[]    findAll()
 * @method BuyOrder[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 *
 * @see \App\Tests\Functional\Infrastructure\Repository\DoctrineBuyOrderRepositoryTest
 */
class BuyOrderRepository extends ServiceEntityRepository implements PositionOrderRepository
{
    private string $exchangeOrderIdContext = BuyOrder::EXCHANGE_ORDER_ID_CONTEXT;

    public function __construct(
        private readonly EventBus $eventBus,
        ManagerRegistry $registry,
    ) {
        parent::__construct($registry, BuyOrder::class);
    }

    public function save(BuyOrder $buyOrder): void
    {
        $save = function () use ($buyOrder) {
            $this->getEntityManager()->persist($buyOrder);
            $this->eventBus->handleEvents($buyOrder);
        };

        $this->getEntityManager()->getConnection()->isTransactionActive()
            ? $save()
            : $this->getEntityManager()->wrapInTransaction($save)
        ;
    }

    public function remove(BuyOrder $order): void
    {
        $this->getEntityManager()->remove($order);
        $this->getEntityManager()->flush();
    }

    /**
     * @return BuyOrder[]
     */
    public function findActive(
        Side $side,
        ?Ticker $nearTicker = null,
        bool $exceptOppositeOrders = false, // Change to true when MakeOppositeOrdersActive-logic has been realised
        callable $qbModifier = null
    ): array {
        $qb = $this->createQueryBuilder('bo')
            ->andWhere('bo.positionSide = :posSide')
            ->andWhere("HAS_ELEMENT(bo.context, '$this->exchangeOrderIdContext') = false")
            ->setParameter(':posSide', $side)
        ;

        if ($exceptOppositeOrders) {
            $qb->andWhere("HAS_ELEMENT(bo.context, 'onlyAfterExchangeOrderExecuted') = false");
        }

        if ($nearTicker) {
            $cond = $side === Side::Buy     ? 'bo.price < :price'           : 'bo.price > :price';
            $price = $side === Side::Buy    ? $nearTicker->indexPrice + 10  : $nearTicker->indexPrice - 10;

            $qb->andWhere($cond)->setParameter(':price', $price);
        }

        if ($qbModifier) {
            $qbModifier($qb);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return BuyOrder[]
     */
    public function findActiveInRange(
        Side $side,
        float $from,
        float $to,
        bool $exceptOppositeOrders = false,
        callable $qbModifier = null
    ): array {
        return $this->findActive(
            side: $side,
            exceptOppositeOrders: $exceptOppositeOrders,
            qbModifier: function (QueryBuilder $qb) use ($from, $to, $qbModifier) {
                if ($qbModifier) {
                    $qbModifier($qb);
                }

                $priceField = $qb->getRootAliases()[0] . '.price';
                $qb
                    ->andWhere(\sprintf('%s > :from and %s < :to', $priceField, $priceField))
                    ->setParameter(':from', $from)
                    ->setParameter(':to', $to);
            }
        );
    }

    public function getNextId(): int
    {
        return $this->_em->getConnection()->executeQuery('SELECT nextval(\'buy_order_id_seq\')')->fetchOne();
    }
}
