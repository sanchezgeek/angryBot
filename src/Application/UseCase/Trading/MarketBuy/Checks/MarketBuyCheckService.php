<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\MarketBuy\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\MarketBuy\Exception\BuyIsNotSafeException;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxInsufficientAvailableBalanceException;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\SandboxStateInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Helper\OutputHelper;
use App\Liquidation\Domain\Assert\LiquidationIsSafeAssertion;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidation\FurtherMainPositionLiquidationCheckParametersInterface;
use Psr\Log\LoggerInterface;

/**
 * @todo | Add interface to mock checks in MarketBuyHandlerTest
 */
readonly class MarketBuyCheckService
{
    /**
     * @throws BuyIsNotSafeException
     */
    public function doChecks(
        MarketBuyEntryDto     $order,
        Ticker                $ticker,
        SandboxStateInterface $currentSandboxState,
        Position              $currentPositionState = null,
    ): void {
        if ($order->force) {
            return;
        }

        $symbol = $order->symbol;
        $markPrice = $ticker->markPrice;
        $lastPrice = $ticker->lastPrice;
        $positionSide = $order->positionSide;

        $sandbox = $this->sandboxFactory->empty($symbol);
        $sandbox->setState($currentSandboxState);

        # order must be bought anyway
        $sandbox->addIgnoredException(SandboxInsufficientAvailableBalanceException::class);

        // creating dto based on MARKET, because source BuyOrder.price might be not actual at this moment
        $sandboxOrder = SandboxBuyOrder::fromMarketBuyEntryDto($order, $lastPrice);

        try {
            $sandbox->processOrders($sandboxOrder);
            $newState = $sandbox->getCurrentState();
            $positionToCheckLiquidation = $newState->getPosition($positionSide);
        } catch (\Throwable $e) {
            $this->appErrorLogger->critical($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'previous' => $e->getPrevious(),
            ]);

            # @todo + logger?
            OutputHelper::warning(sprintf('%s: got "%s" exception while check liquidation is in safe range. Fallback to current position state.', OutputHelper::shortClassName(__METHOD__), $e->getMessage()));

            # use current position liquidation
            $currentPositionState ??= $this->positionService->getPosition($symbol, $positionSide);
            $positionToCheckLiquidation = $currentPositionState;
            // а вообще можно ли делать такой fallback?
        }

        if ($positionToCheckLiquidation->isSupportPosition()) {
            return;
        }

        $liquidationPrice = $positionToCheckLiquidation->liquidationPrice();

        // @todo | liquidation | null
        if ($liquidationPrice->eq(0)) {
            return;
        }

        $safePriceDistance = $this->mainPositionLiquidationParameters->mainPositionSafeLiquidationPriceDelta($symbol, $positionSide, $markPrice->value());
        $isLiquidationOnSafeDistance = LiquidationIsSafeAssertion::assert(
            $positionSide,
            $liquidationPrice,
            $markPrice,
            $safePriceDistance
        );

        if (!$isLiquidationOnSafeDistance) {
            throw BuyIsNotSafeException::liquidationTooNear($liquidationPrice->deltaWith($markPrice), $safePriceDistance);
        }
    }

    public function __construct(
        private PositionServiceInterface $positionService,
        private TradingSandboxFactoryInterface $sandboxFactory,
        private LoggerInterface $appErrorLogger,
        private FurtherMainPositionLiquidationCheckParametersInterface $mainPositionLiquidationParameters
    ) {
    }
}
