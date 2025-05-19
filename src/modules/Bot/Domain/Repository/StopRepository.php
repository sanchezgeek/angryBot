<?php

namespace App\Bot\Domain\Repository;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\Dto\FindStopsDto;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use BackedEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
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
    private string $isAdditionalStopFromLiquidationHandler = Stop::IS_ADDITIONAL_STOP_FROM_LIQUIDATION_HANDLER;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stop::class);
    }

    public function getNextId(): int
    {
        return $this->getEntityManager()->getConnection()->executeQuery('SELECT nextval(\'stop_id_seq\')')->fetchOne();
    }

    public function save(Stop $stop): void
    {
        $save = function () use ($stop) {
            $this->getEntityManager()->persist($stop);
        };

        $this->getEntityManager()->getConnection()->isTransactionActive()
            ? $save()
            : $this->getEntityManager()->wrapInTransaction($save);
    }

    public function remove(Stop $stop): void
    {
        $this->getEntityManager()->remove($stop);
        $this->getEntityManager()->flush();
    }

    /**
     * @return Stop[]
     */
    public function findAllByPositionSide(Symbol $symbol, Side $side, ?callable $qbModifier = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.positionSide = :posSide')->setParameter(':posSide', $side)
            ->andWhere('s.symbol = :symbol')->setParameter(':symbol', $symbol)
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
        Symbol $symbol,
        Side $side,
        ?Ticker $nearTicker = null,
        bool $exceptOppositeOrders = false, // Change to true when MakeOppositeOrdersActive-logic has been realised
        ?callable $qbModifier = null
    ): array {
        return $this->findActiveQB($symbol, $side, $nearTicker, $exceptOppositeOrders, $qbModifier)->getQuery()->getResult();
    }

    public function findActiveQB(
        Symbol $symbol,
        Side $side,
        ?Ticker $nearTicker = null,
        bool $exceptOppositeOrders = false, // Change to true when MakeOppositeOrdersActive-logic has been realised
        ?callable $qbModifier = null
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('s')
            ->andWhere("HAS_ELEMENT(s.context, '$this->exchangeOrderIdContext') = false")
            ->andWhere('s.positionSide = :posSide')->setParameter(':posSide', $side)
            ->andWhere('s.symbol = :symbol')->setParameter(':symbol', $symbol)
        ;

        // а это тут вообще зачем? Для случая ConditionalBO?
        if ($exceptOppositeOrders) {
            $qb->andWhere("HAS_ELEMENT(s.context, 'onlyAfterExchangeOrderExecuted') = false");
        }

        if ($nearTicker) {
            $range = $nearTicker->symbol->makePrice($nearTicker->indexPrice->value() / 600)->value();

            $cond = $side->isShort()    ? '(:price > s.price - s.triggerDelta)'  : '(:price < s.price + s.triggerDelta)';
            $price = $side->isShort()   ? $nearTicker->indexPrice->value() + $range  : $nearTicker->indexPrice->value() - $range;

            $qb->andWhere($cond)->setParameter(':price', $price);
        }

        if ($qbModifier) {
            $qbModifier($qb);
        }

        return $qb;
    }

    /**
     * @param FindStopsDto[] $data
     * @return array<array>
     */
    public function findAllActive(array $data): array
    {
        if (!$data) {
            return [];
        }

        $queries = [];
        $key = 0;
        $parameters = new ArrayCollection();
        foreach ($data as $dto) {
            $symbol = $dto->symbol;
            $currentPrice = $dto->currentPrice;
            $positionSide = $dto->positionSide;
            $ticker = new Ticker($symbol, $currentPrice, $currentPrice, $currentPrice);

            $qb = $this->findActiveQB($symbol, $positionSide, $ticker);
            $queries[] = $qb->getQuery()->getSQL();
            foreach ($qb->getQuery()->getParameters()->toArray() as $param) {
                $parameters->add(new Parameter($key, $param->getValue()));
                $key++;
            }
        }

        $query = implode(' UNION ALL ', $queries);

        $query = str_replace('id_0', 'id', $query); # doctrine bug?
        $query = str_replace('price_1', 'price', $query);
        $query = str_replace('volume_2', 'volume', $query);
        $query = str_replace('trigger_delta_3', 'trigger_delta', $query);
        $query = str_replace('symbol_4', 'symbol', $query);
        $query = str_replace('position_side_5', 'position_side', $query);
        $query = str_replace('context_6', 'context', $query);

        $params = [];
        foreach ($parameters as $parameter) {
            $value = $parameter->getValue();
            $params[$parameter->getName()] = match (true) {
                $value instanceof BackedEnum => $value->value,
                default => $value
            };
        }

        return $this->getEntityManager()->getConnection()->executeQuery($query, $params)->fetchAllAssociative();
    }

    public function findFirstStopUnderPosition(Position $position): ?Stop
    {
        $result = $this->findActive(
            symbol: $position->symbol,
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
            symbol: $position->symbol,
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
            ->andWhere("JSON_ELEMENT_EQUALS(s.context, '$this->exchangeOrderIdContext', '$exchangeOrderId') = true");

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return Stop[]
     */
    public function findPushedToExchange(Symbol $symbol, Side $side): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere("HAS_ELEMENT(s.context, '$this->exchangeOrderIdContext') = true")
            ->andWhere('s.positionSide = :posSide')->setParameter(':posSide', $side)
            ->andWhere('s.symbol = :symbol')->setParameter(':symbol', $symbol)
        ;

        return $qb->getQuery()->getResult();
    }

    public function findActiveCreatedByLiquidationHandler(): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere("HAS_ELEMENT(s.context, '$this->exchangeOrderIdContext') = false")
            ->andWhere("JSON_ELEMENT_EQUALS(s.context, '$this->isAdditionalStopFromLiquidationHandler', 'true') = true")
        ;

        return $qb->getQuery()->getResult();
    }
}
