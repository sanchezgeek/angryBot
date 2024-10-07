<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\MarketBuy\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\MarketBuy\Exception\BuyIsNotSafeException;
use App\Application\UseCase\Trading\MarketBuy\MarketBuyHandler;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Application\UseCase\Trading\Sandbox\TradingSandboxInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Helper\OutputHelper;
use Psr\Log\LoggerInterface;

readonly class MarketBuyCheckService
{
    const SAFE_PRICE_DISTANCE_DEFAULT = MarketBuyHandler::SAFE_PRICE_DISTANCE_DEFAULT;

    /**
     * @throws BuyIsNotSafeException
     */
    public function doChecks(
        MarketBuyEntryDto                    $order,
        Ticker                               $withTicker,
        SandboxState|TradingSandboxInterface $sandbox,
        Position                             $currentPositionState = null,
        float                                $safePriceDistance = self::SAFE_PRICE_DISTANCE_DEFAULT
    ): void {
        if ($order->force) {
            return;
        }

        $positionSide = $order->positionSide;
        $symbol = $order->symbol;

        if ($sandbox instanceof SandboxState) {
            $currentState = $sandbox;
            $sandbox = $this->sandboxFactory->empty($symbol)->setState($currentState);
        }

        // creating dto based on MARKET, because source BuyOrder.price might be not actual at this moment
        $sandboxOrder = SandboxBuyOrder::fromMarketBuyEntryDto($order, $withTicker->lastPrice);

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

        $isLiquidationOnSafeDistance = $positionSide->isShort()
            ? $liquidationPrice->sub($safePriceDistance)->greaterOrEquals($withTicker->markPrice)
            : $liquidationPrice->add($safePriceDistance)->lessOrEquals($withTicker->markPrice);

        if (!$isLiquidationOnSafeDistance) {
            throw BuyIsNotSafeException::liquidationTooNear($liquidationPrice->deltaWith($withTicker->markPrice), $safePriceDistance);
        }
    }

    public function __construct(
        private PositionServiceInterface $positionService,
        private TradingSandboxFactoryInterface $sandboxFactory,
        private LoggerInterface $appErrorLogger,
    ) {
    }
}
