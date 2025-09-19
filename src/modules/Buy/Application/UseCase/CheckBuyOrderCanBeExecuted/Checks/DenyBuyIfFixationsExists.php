<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\MarketBuyCheckDto;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\FixationsFound;
use App\Domain\Price\SymbolPrice;
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

        return !$position || !$position->isPositionInLoss($context->ticker->markPrice);
    }

    public function check(CheckOrderDto|MarketBuyCheckDto $orderDto, TradingCheckContext $context): AbstractTradingCheckResult
    {
        $position = $context->currentPositionState;

        if ($position) {
            $price = $position->entryPrice();
            $note = 'position.entry';
        } elseif ($orderDto->inner->sourceBuyOrder) {
            $price = $orderDto->symbol()->makePrice($orderDto->inner->sourceBuyOrder->getPrice());
            $note = 'order.price';
        }

        if (isset($price)) {
            $markPrice = $context->ticker->markPrice;

            $fixationStopsCount = $this->getFixationStopsCountBeforePrice($orderDto, $price, $markPrice);
            if ($fixationStopsCount > 0) {
                return FixationsFound::create(
                    $this,
                    $fixationStopsCount,
                    sprintf('found %d stops (between %s = %s and ticker = %s)', $fixationStopsCount, $note, $price->value(), $markPrice->value())
                );
            }
        }

        return TradingCheckResult::succeed($this, '');
    }

    private function getFixationStopsCountBeforePrice(MarketBuyCheckDto $orderDto, SymbolPrice $price, SymbolPrice $markPrice): int
    {
        // @todo | buy | checks | DenyBuyIfFixationsExists | add some offset

        $symbol = $orderDto->symbol();
        $positionSide = $orderDto->positionSide();

        return $this->stopsCache->get(
            sprintf('fixations_%s_%s_%s', $symbol->name(), $positionSide->value, $price->value()),
            fn() => $this->stopsQueryService->getBlockingStopsCountBeforePrice($positionSide, $price, $markPrice),
            100
        );
    }

    private static function extractMarketBuyEntryDto(CheckOrderDto|MarketBuyCheckDto $entryDto): MarketBuyEntryDto
    {
        return $entryDto->inner;
    }
}
