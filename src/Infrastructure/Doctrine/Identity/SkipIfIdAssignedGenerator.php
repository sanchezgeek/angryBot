<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Identity;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;

class SkipIfIdAssignedGenerator extends AbstractIdGenerator
{
    public function __construct()
    {
    }

    public function generateId(EntityManagerInterface $em, $entity): mixed
    {
        $classMetadata = $em->getClassMetadata(get_class($entity));
        $identifierField = $classMetadata->getSingleIdentifierFieldName();

        // Получаем текущее значение ID
        $currentValue = $classMetadata->getFieldValue($entity, $identifierField);

        // Если ID уже задан вручную - используем его
        if ($currentValue !== null) {
            return $currentValue;
        }

        return $em->getConnection()->executeQuery('SELECT nextval(\'stop_id_seq\')')->fetchOne();
    }
}
