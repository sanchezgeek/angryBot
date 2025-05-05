<?php

namespace App\Settings\Domain\Repository;

use App\Settings\Domain\Entity\SettingValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SettingValue>
 *
 * @method SettingValue|null find($id, $lockMode = null, $lockVersion = null)
 * @method SettingValue|null findOneBy(array $criteria, array $orderBy = null)
 * @method SettingValue[]    findAll()
 * @method SettingValue[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SettingValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SettingValue::class);
    }

//    public function getNextId(): int
//    {
//        return $this->_em->getConnection()->executeQuery('SELECT nextval(\'setting_value_id_seq\')')->fetchOne();
//    }

    public function save(SettingValue $settingValue): void
    {
        $save = function () use ($settingValue) {
            $this->getEntityManager()->persist($settingValue);
        };

        $this->getEntityManager()->getConnection()->isTransactionActive()
            ? $save()
            : $this->getEntityManager()->wrapInTransaction($save);
    }

    public function remove(SettingValue $settingValue): void
    {
        $this->getEntityManager()->remove($settingValue);
        $this->getEntityManager()->flush();
    }
}
