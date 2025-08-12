<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Buy\Application\Helper\BuyOrderInfoHelper;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Cache\CachedFixationStopsProvider;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\MarketBuyCheckDto;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\BuyCheckFailureEnum;
use App\Domain\Price\SymbolPrice;
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

    public const string ALIAS = 'BUY/FIXATIONS_check';

    public function __construct(
        private StopRepositoryInterface $stopRepository,
        PositionServiceInterface $positionService,
        private CachedFixationStopsProvider $cache,
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
        $order = self::extractMarketBuyEntryDto($orderDto);

        $position = $context->currentPositionState;
        $positionEntryPrice = $position->entryPrice();
        $orderPrice = $context->ticker->markPrice;

        $fixationStopsBeforePositionEntryCount = $this->cache->getFixationStopsCountBeforePositionEntry($context);
        if ($fixationStopsBeforePositionEntryCount > 0) {
            return TradingCheckResult::failed(
                $this,
                BuyCheckFailureEnum::ActiveFixationStopsBeforePositionEntryExists,
                self::info($position, $order, $orderPrice, $positionEntryPrice, sprintf('found %d fixation stops', $fixationStopsBeforePositionEntryCount))
            );
        }

        return TradingCheckResult::succeed($this, self::info($position, $order, $orderPrice, $positionEntryPrice, 'fixation stops not found'));
    }

    private function info(
        Position $position,
        MarketBuyEntryDto $order,
        SymbolPrice $orderPrice,
        SymbolPrice $positionEntryPrice,
        string $reason,
    ): string {
        $identifierInfo = $order->sourceBuyOrder ? BuyOrderInfoHelper::identifier($order->sourceBuyOrder, ' ') : '';

        return sprintf(
            '%s | %s(%s) | entry=%s | %s',
            $position,
            $identifierInfo,
            BuyOrderInfoHelper::shortInlineInfo($order->volume, $orderPrice),
            $positionEntryPrice,
            $reason
        );
    }

    private static function extractMarketBuyEntryDto(CheckOrderDto|MarketBuyCheckDto $entryDto): MarketBuyEntryDto
    {
        return $entryDto->inner;
    }
}
