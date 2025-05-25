<?php

namespace App\Screener\Domain\Repository;

use App\Screener\Domain\Entity\SymbolPriceHistory;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SymbolPriceHistory>
 *
 * @method SymbolPriceHistory|null find($id, $lockMode = null, $lockVersion = null)
 * @method SymbolPriceHistory|null findOneBy(array $criteria, array $orderBy = null)
 * @method SymbolPriceHistory[]    findAll()
 * @method SymbolPriceHistory[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SymbolPriceHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SymbolPriceHistory::class);
    }

    public function save(SymbolPriceHistory $row): void
    {
        $save = function () use ($row) {
            $this->getEntityManager()->persist($row);
        };

        $this->getEntityManager()->getConnection()->isTransactionActive()
            ? $save()
            : $this->getEntityManager()->wrapInTransaction($save);
    }

    public function fundOnMomentOfTime(string $symbolRaw, DateTimeImmutable $onDateTime): ?SymbolPriceHistory
    {
        $qb = $this->createQueryBuilder('h');
        $qb->where('h.symbol = :symbol')->setParameter(':symbol', $symbolRaw);
        $qb->andWhere('h.dateTime = :onDateTime')->setParameter(':onDateTime', $onDateTime);

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function remove(SymbolPriceHistory $settingValue): void
    {
        $this->getEntityManager()->remove($settingValue);
        $this->getEntityManager()->flush();
    }
}
