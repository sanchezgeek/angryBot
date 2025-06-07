<?php

namespace App\Trading\Domain\Symbol\Repository;

use App\Trading\Domain\Symbol\Entity\Symbol;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Symbol>
 */
class SymbolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Symbol::class);
    }

    /**
     * @throws UniqueConstraintViolationException
     */
    public function save(Symbol $symbol): void
    {
        $save = function () use ($symbol) {
            $this->getEntityManager()->persist($symbol);
        };

        $this->getEntityManager()->getConnection()->isTransactionActive()
            ? $save()
            : $this->getEntityManager()->wrapInTransaction($save)
        ;
    }

    public function remove(Symbol $symbol): void
    {
        $this->getEntityManager()->remove($symbol);
        $this->getEntityManager()->flush();
    }

    public function findOneByName(string $name): ?Symbol
    {
        return $this->findOneBy(['name' => $name]);
    }
}
