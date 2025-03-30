<?php

namespace App\Bot\Domain\Repository;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\BuyOrder\Enum\BuyOrderState;
use App\Domain\Position\ValueObject\Side;
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
    private string $onlyAfterExchangeOrderExecutedContext = BuyOrder::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BuyOrder::class);
    }

    public function save(BuyOrder $buyOrder): void
    {
        $save = function () use ($buyOrder) {
            $this->getEntityManager()->persist($buyOrder);
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
        Symbol $symbol,
        Side $side,
        ?Ticker $nearTicker = null,
        bool $exceptOppositeOrders = false, // Change to true when MakeOppositeOrdersActive-logic has been realised
        callable $qbModifier = null
    ): array {
        $qb = $this->createQueryBuilder('bo')
            ->andWhere("HAS_ELEMENT(bo.context, '$this->exchangeOrderIdContext') = false")
            ->andWhere('bo.positionSide = :posSide')->setParameter(':posSide', $side)
            ->andWhere('bo.symbol = :symbol')->setParameter(':symbol', $symbol)
        ;

        if ($exceptOppositeOrders) {
            $qb->andWhere("HAS_ELEMENT(bo.context, 'onlyAfterExchangeOrderExecuted') = false");
        }

        if ($nearTicker) {
            $cond = $side->isShort()  ? 'bo.price > :price' :                   'bo.price < :price';
            $price = $side->isShort() ? $nearTicker->indexPrice->value() - 10 : $nearTicker->indexPrice->value() + 10;

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
    public function findActiveForPush(
        Symbol $symbol,
        Side $side,
        float $currentPrice,
        bool $exceptOppositeOrders = false,
        callable $qbModifier = null
    ): array {
        return $this->findActive(
            symbol: $symbol,
            side: $side,
            exceptOppositeOrders: $exceptOppositeOrders,
            qbModifier: function (QueryBuilder $qb) use ($currentPrice, $side, $qbModifier) {
                if ($qbModifier) {
                    $qbModifier($qb);
                }

                $priceField = $qb->getRootAliases()[0] . '.price';
                if ($side->isShort()) {
                    $qb->andWhere(\sprintf('%s >= :currentPrice', $priceField));
                } else {
                    $qb->andWhere(\sprintf('%s <= :currentPrice', $priceField));
                }
                $qb->setParameter(':currentPrice', $currentPrice);

                $stateField = $qb->getRootAliases()[0] . '.state';
                $qb->andWhere(\sprintf('%s = :activeState', $stateField));
                $qb->setParameter(':activeState', BuyOrderState::Active);
            }
        );
    }

    /**
     * @return BuyOrder[]
     */
    public function findOppositeToStopByExchangeOrderId(Side $side, string $exchangeOrderId): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.positionSide = :posSide')->setParameter(':posSide', $side)
            ->andWhere("JSON_ELEMENT_EQUALS(s.context, '$this->onlyAfterExchangeOrderExecutedContext', '$exchangeOrderId') = true")
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * @return BuyOrder[]
     * @todo MB use current price to find orders?
     */
    public function getIdleOrders(Symbol ...$symbols): array
    {
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.state = :idleState')->setParameter('idleState', BuyOrderState::Idle)
        ;

        if ($symbols) {
            $symbols = array_map(static fn(Symbol $symbol) => $symbol->value, $symbols);
            $qb->andWhere('b.symbol IN (:symbols)')->setParameter(':symbols', $symbols);
        }

        return $qb->getQuery()->getResult();
    }

    public function getNextId(): int
    {
        return $this->_em->getConnection()->executeQuery('SELECT nextval(\'buy_order_id_seq\')')->fetchOne();
    }
}
