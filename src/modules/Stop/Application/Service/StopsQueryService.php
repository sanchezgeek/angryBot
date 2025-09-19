<?php

declare(strict_types=1);

namespace App\Stop\Application\Service;

use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Domain\Position\ValueObject\Side;
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

    public function getBlockingStopsCountBeforePrice(Side $positionSide, SymbolPrice $price, SymbolPrice $tickerPrice): int
    {
        $symbol = $tickerPrice->symbol;

        $stops = $this->stopRepository->findActive(
            symbol: $symbol,
            side: $positionSide,
            qbModifier: function (QueryBuilder $qb, string $alias) use ($positionSide, $price, $tickerPrice) {
                StopRepository::addIsBlockingBuyStopCondition($qb, $alias);

                $qb->andWhere(sprintf('%s %s :positionEntryPrice', QueryHelper::priceField($qb), $positionSide->isShort() ? '<' : '>'));
                $qb->setParameter(':positionEntryPrice', $price->value());

                $qb->andWhere(sprintf('%s %s :tickerPrice', QueryHelper::priceField($qb), $positionSide->isShort() ? '>' : '<'));
                $qb->setParameter(':tickerPrice', $tickerPrice->value());
            }
        );

        return count($stops);
    }
}
