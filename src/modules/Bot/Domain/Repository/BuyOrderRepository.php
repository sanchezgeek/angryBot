<?php

namespace App\Bot\Domain\Repository;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Ticker;
use App\Domain\BuyOrder\Enum\BuyOrderState;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;
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
class BuyOrderRepository extends ServiceEntityRepository
{
    private string $exchangeOrderIdContext = BuyOrder::EXCHANGE_ORDER_ID_CONTEXT;
    private string $onlyAfterExchangeOrderExecutedContext = BuyOrder::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT;

    private const string doublesFlag = BuyOrder::DOUBLE_HASH_FLAG;
    private const string asBuy = BuyOrder::AS_BUY_ON_OPEN_POSITION;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BuyOrder::class);
    }

    public function getNextId(): int
    {
        return $this->getEntityManager()->getConnection()->executeQuery('SELECT nextval(\'buy_order_id_seq\')')->fetchOne();
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
        ?SymbolInterface $symbol = null,
        ?Side $side = null,
        ?Ticker $nearTicker = null,
        bool $exceptOppositeOrders = false, // Change to true when MakeOppositeOrdersActive-logic has been realised
        ?callable $qbModifier = null
    ): array {
        $qb = $this->createQueryBuilder('bo')
            ->andWhere("HAS_ELEMENT(bo.context, '$this->exchangeOrderIdContext') = false")
        ;

        if ($side) {
            $qb->andWhere('bo.positionSide = :posSide')->setParameter(':posSide', $side);
        }

        if ($symbol) {
            $qb->andWhere('bo.symbol = :symbol')->setParameter(':symbol', $symbol->name());
        }

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
        SymbolInterface $symbol,
        Side $side,
        ?float $currentPrice = null,
        bool $exceptOppositeOrders = false,
        ?callable $qbModifier = null
    ): array {
        return $this->findActive(
            symbol: $symbol,
            side: $side,
            exceptOppositeOrders: $exceptOppositeOrders,
            qbModifier: function (QueryBuilder $qb) use ($currentPrice, $side, $qbModifier) {
                if ($qbModifier) {
                    $qbModifier($qb);
                }

                if ($currentPrice !== null) {
                    $priceField = $qb->getRootAliases()[0] . '.price';
                    if ($side->isShort()) {
                        $qb->andWhere(\sprintf('%s >= :currentPrice', $priceField));
                    } else {
                        $qb->andWhere(\sprintf('%s <= :currentPrice', $priceField));
                    }
                    $qb->setParameter(':currentPrice', $currentPrice);
                }

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
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.positionSide = :posSide')->setParameter(':posSide', $side)
            ->andWhere("JSON_ELEMENT_EQUALS(b.context, '$this->onlyAfterExchangeOrderExecutedContext', '$exchangeOrderId') = true")
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * @return BuyOrder[]
     */
    public function getAllIdleOrders(SymbolInterface ...$symbols): array
    {
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.state = :idleState')->setParameter('idleState', BuyOrderState::Idle)
        ;

        if ($symbols) {
            $symbols = array_map(static fn(SymbolInterface $symbol) => $symbol->name(), $symbols);
            $qb->andWhere('b.symbol IN (:symbols)')->setParameter(':symbols', $symbols);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array<Side[]> Symbols names => position sides
     */
    public function getNotExecutedOrdersSymbolsMap(): array
    {
        $query = "select DISTINCT symbol, position_side from buy_order bo WHERE bo.context->'$this->exchangeOrderIdContext' is null";

        $result = $this->getEntityManager()->getConnection()->executeQuery($query)->fetchAllAssociative();

        $map = [];
        foreach ($result as $item) {
            $map[$item['symbol']][] = Side::from($item['position_side']);
        }

        return $map;
    }

    /**
     * @return BuyOrder[]
     */
    public function getOrdersAfterPrice(SymbolInterface $symbol, Side $positionSide, float $price): array
    {
        $qb = $this->createQueryBuilder('b');

        $qb
            ->andWhere('b.symbol = :symbol')->setParameter(':symbol', $symbol->name())
            ->andWhere('b.positionSide = :posSide')->setParameter(':posSide', $positionSide)
        ;

        if ($positionSide->isShort()) {
            $qb->andWhere('b.price <= :price');
        } else {
            $qb->andWhere('b.price >= :price');
        }

        $qb->setParameter(':price', $price);

        return $qb->getQuery()->getResult();
    }

    /**
     * @return BuyOrder[]
     */
    public function getAllActiveOrders(SymbolInterface ...$symbols): array
    {
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.state = :activeState')->setParameter('activeState', BuyOrderState::Active)
        ;

        if ($symbols) {
            $symbols = array_map(static fn(SymbolInterface $symbol) => $symbol->name(), $symbols);
            $qb->andWhere('b.symbol IN (:symbols)')->setParameter(':symbols', $symbols);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return BuyOrder[]
     */
    public function getByDoublesHash(string $hash): array
    {
        $flag = self::doublesFlag;

        $qb = $this->createQueryBuilder('b')
            ->andWhere("HAS_ELEMENT(b.context, '$flag') = true")
            ->andWhere("JSON_ELEMENT_EQUALS(b.context, '$flag', '$hash') = true")
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * @return BuyOrder[]
     */
    public function getCreatedAsBuyOrdersOnOpenPosition(SymbolInterface $symbol, Side $positionSide): array
    {
        $flag = self::asBuy;

        $qb = $this->createQueryBuilder('bo')
            ->andWhere("HAS_ELEMENT(bo.context, '$this->exchangeOrderIdContext') = false")
            ->andWhere('bo.positionSide = :posSide')->setParameter(':posSide', $positionSide)
            ->andWhere('bo.symbol = :symbol')->setParameter(':symbol', $symbol->name())
            ->andWhere("HAS_ELEMENT(bo.context, '$flag') = true");

        return $qb->getQuery()->getResult();
    }
}
