<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\MarketBuyCheckDto;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\FixationsFound;
use App\Stop\Contract\Query\StopsQueryServiceInterface;
use App\Stop\Infrastructure\Cache\StopsCache;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;
use App\Trading\SDK\Check\Mixin\CheckBasedOnCurrentPositionState;

/**
 * @see \App\Tests\Unit\Modules\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\DenyBuyIfFixationsExistsTest
 */
final readonly class DenyBuyIfFixationsExists implements TradingCheckInterface
{
    use CheckBasedOnCurrentPositionState;

    public const string ALIAS = 'FIXATIONS';

    public function __construct(
        PositionServiceInterface $positionService,
        private StopsQueryServiceInterface $stopsQueryService,
        private StopsCache $stopsCache,
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

//        $checkMustBeSkipped = $orderDto->force;
//        if ($checkMustBeSkipped) {
//            return false;
//        }

        $this->enrichContextWithCurrentPositionState($orderDto->symbol, $orderDto->positionSide, $context);
        $position = $context->currentPositionState;

        return
            $position !== null
            && !$position->isPositionInLoss($context->ticker->markPrice)
        ;
    }

    public function check(CheckOrderDto|MarketBuyCheckDto $orderDto, TradingCheckContext $context): AbstractTradingCheckResult
    {
        $fixationStopsBeforePositionEntryCount = $this->getFixationStopsCountBeforePositionEntry($context);
        if ($fixationStopsBeforePositionEntryCount > 0) {
            return FixationsFound::create(
                $this,
                $fixationStopsBeforePositionEntryCount,
                sprintf('found %d stops', $fixationStopsBeforePositionEntryCount)
            );
        }

        return TradingCheckResult::succeed($this, '');
    }

    private function getFixationStopsCountBeforePositionEntry(TradingCheckContext $context): int
    {
        // @todo | buy | checks | DenyBuyIfFixationsExists | add some offset

        $position = $context->currentPositionState;

        return $this->stopsCache->get(
            sprintf('fixations_%s_%s', $position->symbol->name(), $position->side->value),
            fn() => $this->stopsQueryService->getAnyKindOfFixationsCountBeforePositionEntry($position, $context->ticker->markPrice),
            100
        );
    }

    private static function extractMarketBuyEntryDto(CheckOrderDto|MarketBuyCheckDto $entryDto): MarketBuyEntryDto
    {
        return $entryDto->inner;
    }
}
