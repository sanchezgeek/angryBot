<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Helper;

use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;

use function sprintf;

final class QueryHelper
{
    private const ORDERINGS = ['ASC' => true, 'DESC' => true];

    public static function addOrder(QueryBuilder $qb, string $field, string $order): QueryBuilder
    {
        if (!isset(self::ORDERINGS[$order])) {
            throw new InvalidArgumentException(sprintf('Wrong `ORDER BY` clause provided (%s)', $order));
        }

        $table = $qb->getRootAliases()[0];

        return $qb->addOrderBy(sprintf('%s.%s', $table, $field), $order);
    }
}
