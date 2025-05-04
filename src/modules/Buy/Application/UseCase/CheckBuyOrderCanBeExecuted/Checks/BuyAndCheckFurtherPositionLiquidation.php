<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxInsufficientAvailableBalanceException;
use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Mixin\SandboxExecutionAwareTrait;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\MarketBuyCheckDto;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\FurtherPositionLiquidationAfterBuyIsTooClose;
use App\Liquidation\Domain\Assert\LiquidationIsSafeAssertion;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;
use App\Trading\SDK\Check\Mixin\CheckBasedOnCurrentPositionState;
use App\Trading\SDK\Check\Mixin\CheckBasedOnExecutionInSandbox;
use Throwable;

/**
 * @see \App\Tests\Unit\Modules\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyAndCheckFurtherPositionLiquidationTest
 */
final class BuyAndCheckFurtherPositionLiquidation implements TradingCheckInterface
{
    use SandboxExecutionAwareTrait;
    use CheckBasedOnExecutionInSandbox;
    use CheckBasedOnCurrentPositionState;

    public function __construct(
        private readonly TradingParametersProviderInterface $parameters,
        PositionServiceInterface $positionService,
        TradingSandboxFactoryInterface $sandboxFactory,
        SandboxStateFactoryInterface $sandboxStateFactory,
    ) {
        $this->initSandboxServices($sandboxFactory, $sandboxStateFactory);
        $this->initPositionService($positionService);
    }

    public function supports(CheckOrderDto|MarketBuyCheckDto $orderDto, TradingCheckContext $context): bool
    {
        $orderDto = self::extractMarketBuyEntryDto($orderDto);

        // position and order mismatch? => logic
        $this->enrichContextWithCurrentPositionState($orderDto->symbol, $orderDto->positionSide, $context);

        $symbol = $orderDto->symbol;
        $position = $context->currentPositionState;
        $checkMustBeSkipped = $orderDto->force;

        return
            !$checkMustBeSkipped && (
                $position->isPositionWithoutHedge()
                || $position->isMainPosition()
                || $symbol->roundVolume($position->size + $orderDto->volume) > $position->oppositePosition->size
            )
        ;
    }

    public function check(CheckOrderDto|MarketBuyCheckDto $orderDto, TradingCheckContext $context): AbstractTradingCheckResult
    {
        $order = self::extractMarketBuyEntryDto($orderDto);

        if ($order->force) {
            return TradingCheckResult::succeed($this, 'force flag is set');
        }

        $this->enrichContextWithCurrentSandboxState($context);

        $ticker = $context->ticker;
        $symbol = $order->symbol;
        $lastPrice = $ticker->lastPrice;
        $positionSide = $order->positionSide;

        $sandbox = $this->sandboxFactory->empty($symbol);
        $sandbox->setState($context->currentSandboxState);
        # in sandbox order must be bought anyway
        $sandbox->addIgnoredException(SandboxInsufficientAvailableBalanceException::class);

        // creating dto based on MARKET, because source BuyOrder.price might be not actual at this moment
        $sandboxOrder = SandboxBuyOrder::fromMarketBuyEntryDto($order, $lastPrice);
        try {
            $sandbox->processOrders($sandboxOrder);

        } catch (Throwable $e) {
            self::processSandboxExecutionException($e, $sandboxOrder);
        }

        $newState = $sandbox->getCurrentState();
        $positionAfterBuy = $newState->getPosition($positionSide);

        if ($positionAfterBuy->isSupportPosition()) {
            // @todo skip check at all if initially position also is support
            return TradingCheckResult::succeed($this, 'position became support after buy');
        }

        $executionPrice = $ticker->lastPrice;
        $liquidationPrice = $positionAfterBuy->liquidationPrice();

        // @todo | liquidation | null
        if ($liquidationPrice->eq(0)) {
            return TradingCheckResult::succeed(
                $this,
                sprintf('%s | %sqty=%s, price=%s | result position has no liquidation', $positionAfterBuy, $order->sourceBuyOrder ? sprintf('id=%d, ', $order->sourceBuyOrder->getId()) : '', $order->volume, $executionPrice)
            );
        }

//        // @todo separated strategy if support in loss / main not in loss (select price between ticker and entry / or add distance between support and ticker)
//        $withPrice = $mainPosition->isPositionInLoss($tickerPrice) ? $tickerPrice : $mainPosition->entryPrice();
        $withPrice = $ticker->markPrice;
        $safePriceDistance = $this->parameters->safeLiquidationPriceDelta($symbol, $positionSide, $withPrice->value());
        $isLiquidationOnSafeDistance = LiquidationIsSafeAssertion::assert(
            $positionSide,
            $liquidationPrice,
            $withPrice,
            $safePriceDistance
        );

        $info = sprintf(
            '%s | %sqty=%s, price=%s | safeDistance=%s, liquidation=%s, delta=%s',
            $positionAfterBuy, $order->sourceBuyOrder ? sprintf('id=%d, ', $order->sourceBuyOrder->getId()) : '', $order->volume, $executionPrice, $safePriceDistance, $liquidationPrice, $liquidationPrice->deltaWith($withPrice)
        );

        return
            !$isLiquidationOnSafeDistance
                ? FurtherPositionLiquidationAfterBuyIsTooClose::create($this, $withPrice, $liquidationPrice, $safePriceDistance, $info)
                : TradingCheckResult::succeed($this, $info)
        ;
    }

    private static function extractMarketBuyEntryDto(CheckOrderDto|MarketBuyCheckDto $entryDto): MarketBuyEntryDto
    {
        return $entryDto->inner;
    }
}
