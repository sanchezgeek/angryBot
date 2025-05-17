<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxInsufficientAvailableBalanceException;
use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Handler\UnexpectedSandboxExecutionExceptionHandler;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\MarketBuyCheckDto;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\FurtherPositionLiquidationAfterBuyIsTooClose;
use App\Liquidation\Domain\Assert\PositionLiquidationIsSafeAssertion;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\Settings\SafePriceDistanceSettings;
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
final readonly class BuyAndCheckFurtherPositionLiquidation implements TradingCheckInterface
{
    use CheckBasedOnExecutionInSandbox;
    use CheckBasedOnCurrentPositionState;

    const ALIAS = 'check-liq-before-buy';

    public function __construct(
        private AppSettingsProviderInterface $settings,
        private TradingParametersProviderInterface $parameters,
        private UnexpectedSandboxExecutionExceptionHandler $unexpectedSandboxExceptionHandler,
        PositionServiceInterface $positionService,
        TradingSandboxFactoryInterface $sandboxFactory,
        SandboxStateFactoryInterface $sandboxStateFactory,
    ) {
        $this->initSandboxServices($sandboxFactory, $sandboxStateFactory);
        $this->initPositionService($positionService);
    }

    public function alias(): string
    {
        return self::ALIAS;
    }

    /**
     * @todo | buy/check | What if there is no position opened? In this case there is also no position state => fatal
     */
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
            $this->unexpectedSandboxExceptionHandler->handle($this, $e, $sandboxOrder);
        }

        $newState = $sandbox->getCurrentState();
        $positionAfterBuy = $newState->getPosition($positionSide);

        if ($positionAfterBuy->isSupportPosition()) {
            // @todo | buy/check how is it possible?
            // @todo mb need skip check at all if initially position is support
            return TradingCheckResult::succeed($this, 'position became support after buy');
        }

        $executionPrice = $ticker->lastPrice;
        $liquidationPrice = $positionAfterBuy->liquidationPrice();

        // @todo | liquidation | null
        $identifierInfo = $order->sourceBuyOrder ? sprintf('id=%d, ', $order->sourceBuyOrder->getId()) : '';
        if ($liquidationPrice->eq(0)) {
            return TradingCheckResult::succeed(
                $this,
                sprintf('%s | %sqty=%s, price=%s | liq=0', $positionAfterBuy, $identifierInfo, $order->volume, $executionPrice)
            );
        }

// @todo | buy/check | separated strategy if support in loss / main not in loss (select price between ticker and entry / or add distance between support and ticker)
        $withPrice = $ticker->markPrice;
        $safeDistance = $this->parameters->safeLiquidationPriceDelta($symbol, $positionSide, $withPrice->value());
// @todo | settings | it also can be setting for whole class to define hot to retrieve setting (with alternatives / exact)
        $safePriceAssertionStrategy = $this->settings->required(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Apply_Strategy, $symbol, $positionSide));
        $isLiquidationOnSafeDistance = PositionLiquidationIsSafeAssertion::assert($positionAfterBuy, $ticker, $safeDistance, $safePriceAssertionStrategy);

        $info = sprintf(
            '%s | %sqty=%s, price=%s | liq=%s, Δ=%s, safe=%s',
            $positionAfterBuy,
            $identifierInfo,
            $order->volume,
            $executionPrice,
            $liquidationPrice,
            $liquidationPrice->deltaWith($withPrice),
            $symbol->makePrice($safeDistance)
        );

        return
            !$isLiquidationOnSafeDistance
                ? FurtherPositionLiquidationAfterBuyIsTooClose::create($this, $withPrice, $liquidationPrice, $safeDistance, $info)
                : TradingCheckResult::succeed($this, $info)
        ;
    }

    private static function extractMarketBuyEntryDto(CheckOrderDto|MarketBuyCheckDto $entryDto): MarketBuyEntryDto
    {
        return $entryDto->inner;
    }
}
