<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\MarketBuyCheckDto;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\BuyCheckFailureEnum;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;
use App\Trading\SDK\Check\Mixin\CheckBasedOnCurrentPositionState;
use Doctrine\ORM\QueryBuilder;

/**
 * @see \App\Tests\Unit\Modules\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\DenyBuyIfFixationsExistsTest
 */
final readonly class DenyBuyIfFixationsExists implements TradingCheckInterface
{
    use CheckBasedOnCurrentPositionState;

    public const string ALIAS = 'BUY/FIXATIONS_check';

    public function __construct(
        private StopRepositoryInterface $stopRepository,
        PositionServiceInterface $positionService,
    ) {
        $this->initPositionService($positionService);
    }

    public function alias(): string
    {
        return self::ALIAS;
    }

    public function supports(CheckOrderDto|MarketBuyCheckDto $orderDto, TradingCheckContext $context): bool
    {
        $orderDto = self::extractMarketBuyEntryDto($orderDto);

        $this->enrichContextWithCurrentPositionState($orderDto->symbol, $orderDto->positionSide, $context);
        $position = $context->currentPositionState;

        return
            $position !== null
            && !$position->isPositionInLoss($context->ticker->markPrice)
        ;
    }

    public function check(CheckOrderDto|MarketBuyCheckDto $orderDto, TradingCheckContext $context): AbstractTradingCheckResult
    {
        $fixationStopsBeforePositionEntry = $this->getFixationStopsBeforePositionEntry($context);

        if ($fixationStopsBeforePositionEntry) {
            return TradingCheckResult::failed(
                $this,
                BuyCheckFailureEnum::ActiveFixationStopsBeforePositionEntryExists,
                sprintf('found %d fixation stops before position entry', count($fixationStopsBeforePositionEntry))
            );
        }

        return TradingCheckResult::succeed($this, 'fixation stops not found');
    }

    /**
     * @todo Если стоп будет pushedToExchange, то проверка будет пройдена
     *
     * @return Stop[]
     */
    private function getFixationStopsBeforePositionEntry(TradingCheckContext $context): array
    {
        $position = $context->currentPositionState;
        $ticker = $context->ticker;

        return $this->stopRepository->findActive(
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
    }

    private static function extractMarketBuyEntryDto(CheckOrderDto|MarketBuyCheckDto $entryDto): MarketBuyEntryDto
    {
        return $entryDto->inner;
    }
}
