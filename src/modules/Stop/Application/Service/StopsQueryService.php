<?php

declare(strict_types=1);

namespace App\Stop\Application\Service;

use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Domain\Price\SymbolPrice;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use App\Stop\Contract\Query\StopsQueryServiceInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class StopsQueryService implements StopsQueryServiceInterface
{
    public function __construct(
        private StopRepositoryInterface $stopRepository
    ) {
    }

    public function getAnyKindOfFixationsCountBeforePositionEntry(Position $position, SymbolPrice $tickerPrice): int
    {
        $stops = $this->stopRepository->findActive(
            symbol: $position->symbol,
            side: $position->side,
            qbModifier: function (QueryBuilder $qb, string $alias) use ($position, $tickerPrice) {
                StopRepository::addIsAnyKindOfFixationCondition($qb, $alias);

                $qb->andWhere(sprintf('%s %s :positionEntryPrice', QueryHelper::priceField($qb), $position->isShort() ? '<' : '>'));
                $qb->setParameter(':positionEntryPrice', $position->entryPrice);

                $qb->andWhere(sprintf('%s %s :tickerPrice', QueryHelper::priceField($qb), $position->isShort() ? '>' : '<'));
                $qb->setParameter(':tickerPrice', $tickerPrice->value());
            }
        );

        return count($stops);
    }
}
