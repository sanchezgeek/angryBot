<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Mixin\SandboxExecutionAwareTrait;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Liquidation\Domain\Assert\LiquidationIsSafeAssertion;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Result\StopCheckFailureEnum;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\StopCheckDto;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;
use App\Trading\SDK\Check\Mixin\CheckBasedOnCurrentPositionState;
use App\Trading\SDK\Check\Mixin\CheckBasedOnExecutionInSandbox;
use Throwable;

/**
 * @see \App\Tests\Unit\Modules\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidationCheckTest
 */
final class StopAndCheckFurtherMainPositionLiquidation implements TradingCheckInterface
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

    // @todo test?
    public function supports(CheckOrderDto|StopCheckDto $orderDto, TradingCheckContext $context): bool
    {
        $stop = self::extractStopFromEntryDto($orderDto);

        $this->enrichContextWithCurrentPositionState($stop->getSymbol(), $stop->getPositionSide(), $context);

        return $context->currentPositionState->isSupportPosition();
    }

    public function check(CheckOrderDto|StopCheckDto $orderDto, TradingCheckContext $context): TradingCheckResult
    {
        $this->enrichContextWithCurrentSandboxState($context);

        $stop = self::extractStopFromEntryDto($orderDto);

        $closingPosition = $context->currentSandboxState->getPosition($stop->getPositionSide());

        $sandbox = $this->sandboxFactory->empty($stop->getSymbol());
        $sandbox->setState($context->currentSandboxState);

        $sandboxOrder = SandboxStopOrder::fromStop($stop);
        try {
            $sandbox->processOrders($sandboxOrder);
        } catch (Throwable $e) {
            self::processSandboxExecutionException($e, $sandboxOrder);
        }

        $newState = $sandbox->getCurrentState();
        $mainPosition = $newState->getPosition($closingPosition->side->getOpposite());
        $mainPositionLiquidationPriceNew = $mainPosition->liquidationPrice();

        $ticker = $context->ticker;
        $executionPrice = $orderDto->priceValueWillBeingUsedAtExecution();

        // @todo | liquidation | null
        if ($mainPositionLiquidationPriceNew->eq(0)) {
            return TradingCheckResult::succeed(
                $this,
                sprintf('%s | id=%d, qty=%s, price=%s | liquidation=%s', $closingPosition, $stop->getId(), $stop->getVolume(), $executionPrice, $mainPositionLiquidationPriceNew)
            );
        }

        // @todo check liq params

        $tickerPrice = $ticker->markPrice;
        // @todo separated strategy if support in loss / main not in loss (select price between ticker and entry / or add distance between support and ticker)
        $withPrice = $mainPosition->isPositionInLoss($tickerPrice) ? $tickerPrice : $mainPosition->entryPrice();
        $safeDistance = $this->parameters->safeLiquidationPriceDelta($mainPosition->symbol, $mainPosition->side, $withPrice->value());
        $isLiquidationOnSafeDistance = LiquidationIsSafeAssertion::assert($mainPosition->side, $mainPositionLiquidationPriceNew, $withPrice, $safeDistance);

        $info = sprintf(
            '%s | id=%d, qty=%s, price=%s | safeDistance=%s, liquidation=%s',
            $closingPosition, $stop->getId(), $stop->getVolume(), $executionPrice, $safeDistance, $mainPositionLiquidationPriceNew
        );

        return
            !$isLiquidationOnSafeDistance
                ? TradingCheckResult::failed($this, StopCheckFailureEnum::FurtherMainPositionLiquidationIsTooClose, $info)
                : TradingCheckResult::succeed($this, $info)
        ;
    }

    private static function extractStopFromEntryDto(CheckOrderDto|StopCheckDto $entryDto): Stop
    {
        return $entryDto->inner;
    }
}
