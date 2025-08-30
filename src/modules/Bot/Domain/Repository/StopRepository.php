<?php

namespace App\Bot\Domain\Repository;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\Dto\FindStopsDto;
use App\Bot\Domain\Ticker;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\PriceRange;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use App\Trading\Domain\Symbol\SymbolInterface;
use BackedEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use RuntimeException;

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
    private const string isAdditionalStopFromLiquidationHandler = Stop::IS_ADDITIONAL_STOP_FROM_LIQUIDATION_HANDLER;
    private const string createdAfterOtherSymbolLoss = Stop::CREATED_AFTER_OTHER_SYMBOL_LOSS;
    private const string createdAfterFixHedgeOppositePosition = Stop::CREATED_AFTER_FIX_HEDGE_OPPOSITE_POSITION;

    private string $exchangeOrderIdContext = Stop::EXCHANGE_ORDER_ID_CONTEXT;

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
    public function findAllByParams(?SymbolInterface $symbol = null, ?Side $side = null, ?callable $qbModifier = null): array
    {
        $qb = $this->createQueryBuilder('s');

        if ($side) {
            $qb->andWhere('s.positionSide = :posSide')->setParameter(':posSide', $side);
        }

        if ($symbol) {
            $qb->andWhere('s.symbol = :symbol')->setParameter(':symbol', $symbol->name());
        }

        if ($qbModifier) {
            $qbModifier($qb);
        }

        return $qb->getQuery()->getResult();
    }

    public function findActiveInRange(
        SymbolInterface $symbol,
        Side $side,
        PriceRange $priceRange,
        bool $exceptOppositeOrders = false,
        ?callable $qbModifier = null
    ): array {
        return $this->findActiveQB($symbol, $side, null, $exceptOppositeOrders, function (QueryBuilder $qb) use ($priceRange, $qbModifier) {
            $qbModifier && $qbModifier($qb);

            $priceField = $qb->getRootAliases()[0] . '.price';
            $from = $priceRange->from()->value();
            $to = $priceRange->to()->value();

            $qb->andWhere($priceField . ' BETWEEN :priceFrom AND :priceTo')->setParameter(':priceFrom', $from)->setParameter(':priceTo', $to);
        })->getQuery()->getResult();
    }

    /**
     * @return Stop[]
     */
    public function findActive(
        ?SymbolInterface $symbol = null,
        ?Side $side = null,
        ?Ticker $nearTicker = null,
        bool $exceptOppositeOrders = false, // Change to true when MakeOppositeOrdersActive-logic has been realised
        ?callable $qbModifier = null
    ): array {
        return $this->findActiveQB($symbol, $side, $nearTicker, $exceptOppositeOrders, $qbModifier)->getQuery()->getResult();
    }

    public function findActiveQB(
        ?SymbolInterface $symbol = null,
        ?Side $side = null,
        ?Ticker $nearTicker = null,
        bool $exceptOppositeOrders = false, // Change to true when MakeOppositeOrdersActive-logic has been realised
        ?callable $qbModifier = null
    ): QueryBuilder {
        $alias = 's';

        $qb = $this->createQueryBuilder($alias)
            ->andWhere("HAS_ELEMENT(s.context, '$this->exchangeOrderIdContext') = false")
        ;

        if ($side) {
            $qb->andWhere('s.positionSide = :posSide')->setParameter(':posSide', $side);
        }

        if ($symbol) {
            $qb->andWhere('s.symbol = :symbol')->setParameter(':symbol', $symbol->name());
        }

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
            $qbModifier($qb, $alias);
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

        $query = preg_replace(
            ['/id_\d+/', '/price_\d+/', '/volume_\d+/', '/trigger_delta_\d+/', '/symbol_\d+/', '/position_side_\d+/', '/context_\d+/'],
            ['id',       'price',       'volume',       'trigger_delta',       'symbol',       'position_side',       'context'],
            implode(' UNION ALL ', $queries)
        );

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
    public function findPushedToExchange(SymbolInterface $symbol, Side $side): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere("HAS_ELEMENT(s.context, '$this->exchangeOrderIdContext') = true")
            ->andWhere('s.positionSide = :posSide')->setParameter(':posSide', $side)
            ->andWhere('s.symbol = :symbol')->setParameter(':symbol', $symbol->name())
        ;

        return $qb->getQuery()->getResult();
    }

    public function findActiveCreatedByLiquidationHandler(): array
    {
        $qb = $this->createQueryBuilder($alias = 's')->andWhere("HAS_ELEMENT(s.context, '$this->exchangeOrderIdContext') = false");
        $qb = self::addIsAdditionalStopFromLiqHandlerCondition($qb, $alias);

        return $qb->getQuery()->getResult();
    }

    public static function addIsAdditionalStopFromLiqHandlerCondition(QueryBuilder $qb, ?string $alias = null): QueryBuilder
    {
        $alias = $alias ?? QueryHelper::rootAlias($qb);
        $flagName = self::isAdditionalStopFromLiquidationHandler;

        return $qb->andWhere("JSON_ELEMENT_EQUALS($alias.context, '$flagName', 'true') = true");
    }

    public static function addIsCreatedAfterOtherSymbolLossCondition(QueryBuilder $qb, ?string $alias = null): QueryBuilder
    {
        $alias = $alias ?? QueryHelper::rootAlias($qb);
        $flagAfterOtherSymbolLoss = self::createdAfterOtherSymbolLoss;
        $flagAfterFixHedge = self::createdAfterFixHedgeOppositePosition;

        return $qb
            ->andWhere(
                "JSON_ELEMENT_EQUALS($alias.context, '$flagAfterOtherSymbolLoss', 'true') = true"
                . " OR JSON_ELEMENT_EQUALS($alias.context, '$flagAfterFixHedge', 'true') = true"
            )
        ;
    }

    public function findStopsWithFakeExchangeOrderId(): array
    {
        $fakeExchangeOrderId = Stop::FAKE_EXCHANGE_ORDER_ID;

        $qb = $this->createQueryBuilder('s')
            ->andWhere("JSON_ELEMENT_EQUALS(s.context, '$this->exchangeOrderIdContext', '$fakeExchangeOrderId') = true");

        return $qb->getQuery()->getResult();
    }
}
