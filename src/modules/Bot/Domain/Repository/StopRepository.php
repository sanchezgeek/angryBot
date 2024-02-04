<?php

namespace App\Bot\Domain\Repository;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Domain\Position\ValueObject\Side;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Stop>
 *
 * @method Stop|null find($id, $lockMode = null, $lockVersion = null)
 * @method Stop|null findOneBy(array $criteria, array $orderBy = null)
 * @method Stop[]    findAll()
 * @method Stop[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StopRepository extends ServiceEntityRepository implements PositionOrderRepository, StopRepositoryInterface
{
    private string $exchangeOrderIdContext = Stop::EXCHANGE_ORDER_ID_CONTEXT;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stop::class);
    }

    public function save(Stop $stop): void
    {
        $save = function () use ($stop) {
            $this->getEntityManager()->persist($stop);
        };

        $this->getEntityManager()->getConnection()->isTransactionActive()
            ? $save()
            : $this->getEntityManager()->wrapInTransaction($save)
        ;
    }

    public function remove(Stop $stop): void
    {
        $this->getEntityManager()->remove($stop);
        $this->getEntityManager()->flush();
    }

    /**
     * @return Stop[]
     */
    public function findAllByPositionSide(Side $side, callable $qbModifier = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.positionSide = :posSide')
            ->setParameter(':posSide', $side)
        ;

        if ($qbModifier) {
            $qbModifier($qb);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Stop[]
     */
    public function findActive(
        Side $side,
        ?Ticker $nearTicker = null,
        bool $exceptOppositeOrders = false, // Change to true when MakeOppositeOrdersActive-logic has been realised
        callable $qbModifier = null
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.positionSide = :posSide')
            ->andWhere("HAS_ELEMENT(s.context, '$this->exchangeOrderIdContext') = false")
            ->setParameter(':posSide', $side)
        ;

        if ($exceptOppositeOrders) {
            $qb->andWhere("HAS_ELEMENT(s.context, 'onlyAfterExchangeOrderExecuted') = false");
        }

        if ($nearTicker) {
            $cond = $side->isShort()    ? '(:price > s.price - s.triggerDelta)'  : '(:price < s.price + s.triggerDelta)';
            $price = $side->isShort()   ? $nearTicker->indexPrice->value() + 50  : $nearTicker->indexPrice->value() - 50;

            $qb->andWhere($cond)->setParameter(':price', $price);
        }

        if ($qbModifier) {
            $qbModifier($qb);
        }

        return $qb->getQuery()->getResult();
    }

    public function findFirstStopUnderPosition(Position $position): ?Stop
    {
        $result = $this->findActive(
            side: $position->side,
            qbModifier: static function (QueryBuilder $qb) use ($position) {
                $qb->andWhere(
                    $qb->getRootAliases()[0] . '.price' . ($position->side === Side::Sell ? '> :entryPrice' : '< :entryPrice')
                )->setParameter(':entryPrice', $position->entryPrice);
                $qb->addOrderBy(
                    new OrderBy(
                        $qb->getRootAliases()[0] . '.price', $position->side === Side::Sell ? 'ASC' : 'DESC'
                    )
                );

                $qb->setMaxResults(1);
            }
        );

        if ($firstPositionStop = $result[0] ?? null) {
            return $firstPositionStop;
        }

        return null;
    }

    public function findFirstPositionStop(Position $position): ?Stop
    {
        $result = $this->findActive(
            side: $position->side,
            qbModifier: static function (QueryBuilder $qb) use ($position) {
                $qb->addOrderBy(
                    new OrderBy(
                        $qb->getRootAliases()[0] . '.price', $position->side === Side::Sell ? 'ASC' : 'DESC'
                    )
                );

                $qb->setMaxResults(1);
            }
        );

        if ($firstPositionStop = $result[0] ?? null) {
            return $firstPositionStop;
        }

        return null;
    }

    public function findByExchangeOrderId(Side $side, string $exchangeOrderId): ?Stop
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.positionSide = :posSide')->setParameter(':posSide', $side)
            ->andWhere("JSON_ELEMENT_EQUALS(s.context, '$this->exchangeOrderIdContext', '$exchangeOrderId') = true")
        ;

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function getNextId(): int
    {
        return $this->_em->getConnection()->executeQuery('SELECT nextval(\'stop_id_seq\')')->fetchOne();
    }
}
