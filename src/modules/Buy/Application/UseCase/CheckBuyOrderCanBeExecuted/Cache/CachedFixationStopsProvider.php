<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Cache;

use App\Application\Cache\AbstractCacheService;
use App\Application\Cache\CacheServiceInterface;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use Doctrine\ORM\QueryBuilder;

final class CachedFixationStopsProvider extends AbstractCacheService
{
    public function __construct(
        CacheServiceInterface $cache,
        private readonly StopRepositoryInterface $stopRepository,
    ) {
        parent::__construct($cache);
    }

    protected static function getDefaultTtl(): int
    {
        return 300;
    }

    /**
     * @todo Если стоп будет pushedToExchange, то проверка будет пройдена
     */
    public function getFixationStopsCountBeforePositionEntry(TradingCheckContext $context): int
    {
        $position = $context->currentPositionState;

        $key = sprintf('fixations_%s_%s', $position->symbol->name(), $position->side->value);

        return $this->get($key, function() use ($context) {
            $position = $context->currentPositionState;
            $ticker = $context->ticker;

            $stops = $this->stopRepository->findActive(
                symbol: $position->symbol,
                side: $position->side,
                qbModifier: function (QueryBuilder $qb, string $alias) use ($position, $ticker) {
                    StopRepository::addIsCreatedAfterOtherSymbolLossCondition($qb, $alias);

                    $qb->andWhere(sprintf('%s %s :positionEntryPrice', QueryHelper::priceField($qb), $position->isShort() ? '<' : '>'));
                    $qb->setParameter(':positionEntryPrice', $position->entryPrice);

                    $qb->andWhere(sprintf('%s %s :tickerPrice', QueryHelper::priceField($qb), $position->isShort() ? '>' : '<'));
                    $qb->setParameter(':tickerPrice', $ticker->markPrice);
                }
            );

            return count($stops);
        });
    }
}
