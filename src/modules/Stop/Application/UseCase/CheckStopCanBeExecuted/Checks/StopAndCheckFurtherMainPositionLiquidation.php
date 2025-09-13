<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Handler\UnexpectedSandboxExecutionExceptionHandler;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Liquidation\Domain\Assert\PositionLiquidationIsSafeAssertion;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Result\StopCheckFailureEnum;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\StopCheckDto;
use App\Trading\Application\Parameters\TradingDynamicParameters;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;
use App\Trading\SDK\Check\Exception\ReferencedPositionNotFound;
use App\Trading\SDK\Check\Mixin\CheckBasedOnCurrentPositionState;
use App\Trading\SDK\Check\Mixin\CheckBasedOnExecutionInSandbox;
use Throwable;

/**
 * @see \App\Tests\Unit\Modules\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidationCheckTest
 */
final readonly class StopAndCheckFurtherMainPositionLiquidation implements TradingCheckInterface
{
    use CheckBasedOnExecutionInSandbox;
    use CheckBasedOnCurrentPositionState;

    public const string ALIAS = 'STOP-SUPPORT/MAIN-LIQUIDATION_check';

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
     * @inheritDoc
     *
     * @param StopCheckDto $orderDto
     * @todo | stop/check | test?
     */
    public function supports(CheckOrderDto $orderDto, TradingCheckContext $context): bool
    {
        $stop = self::extractStopFromEntryDto($orderDto);

        if ($stop->isSupportChecksSkipped()) {
            return false;
        }

        $symbol = $stop->getSymbol();
        $side = $stop->getPositionSide();

        $this->enrichContextWithCurrentPositionState($symbol, $side, $context);

        if (!$context->currentPositionState) {
            // e.g. position closed at this moment
            throw new ReferencedPositionNotFound(sprintf('Trying to find position by %s %s, but cannot', $symbol->name(), $side->value));
        }

        return $context->currentPositionState->isSupportPosition();
        // @todo | stop/check seems it doesn't work for фт unknown reason
//        $oppositePosition = $context->currentPositionState->oppositePosition;
//
//        return
//            $oppositePosition && (
//                $context->currentPositionState->isSupportPosition()
//                || $context->currentPositionState->size - $stop->getVolume() < $oppositePosition->size
//            )
//            ;
    }

    /**
     * @param StopCheckDto $orderDto
     * @inheritdoc
     */
    public function check(CheckOrderDto $orderDto, TradingCheckContext $context): TradingCheckResult
    {
        $this->enrichContextWithCurrentSandboxState($context);

        $stop = self::extractStopFromEntryDto($orderDto);
        $symbol = $stop->getSymbol();

        $closingPosition = $context->currentSandboxState->getPosition($stop->getPositionSide());

        $sandbox = $this->sandboxFactory->empty($symbol);
        $sandbox->setState($context->currentSandboxState);

        $sandboxOrder = SandboxStopOrder::fromStop($stop, $orderDto->priceValueWillBeingUsedAtExecution());

// @todo | stop/check | use ticker price if by market?

        try {
            $sandbox->processOrders($sandboxOrder);
        } catch (Throwable $e) {
            $this->unexpectedSandboxExceptionHandler->handle($this, $e, $sandboxOrder);
        }

        $newState = $sandbox->getCurrentState();
        $mainPositionStateAfterExec = $newState->getPosition($closingPosition->side->getOpposite());
        $mainPositionLiquidation = $mainPositionStateAfterExec->liquidationPrice();

        $ticker = $context->ticker;
        $executionPrice = $orderDto->priceValueWillBeingUsedAtExecution();

        // @todo | liquidation | null
        if ($mainPositionLiquidation->eq(0)) {
            return TradingCheckResult::succeed(
                $this,
                sprintf('%s | id=%d, qty=%s, price=%s | liq=0', $closingPosition, $stop->getId(), $stop->getVolume(), $executionPrice)
            );
        }

// @todo | stop/check | check liq params
// @todo | stop/check | separated strategy if support in loss / main not in loss (select price between ticker and entry / or add distance between support and ticker)
        $withPrice = $mainPositionStateAfterExec->isPositionInLoss($ticker->markPrice) ? $ticker->markPrice : $mainPositionStateAfterExec->entryPrice();
        $safeDistance = $this->parameters->safeLiquidationPriceDelta($mainPositionStateAfterExec->symbol, $mainPositionStateAfterExec->side, $withPrice->value());
        $safePriceAssertionStrategy = TradingDynamicParameters::safePriceDistanceApplyStrategy($symbol, $mainPositionStateAfterExec->side);
        $isLiquidationOnSafeDistanceResult = PositionLiquidationIsSafeAssertion::assert($mainPositionStateAfterExec, $ticker, $safeDistance, $safePriceAssertionStrategy);
        $isLiquidationOnSafeDistance = $isLiquidationOnSafeDistanceResult->success;
        $usedPrice = $isLiquidationOnSafeDistanceResult->usedPrice;

        $info = sprintf(
            '%s | id=%d, qty=%s, price=%s | liq=%s, Δ=%s, safe=%s',
            $closingPosition,
            $stop->getId(),
            $stop->getVolume(),
            $executionPrice,
            $mainPositionLiquidation,
            $mainPositionLiquidation->deltaWith($usedPrice),
            $symbol->makePrice($safeDistance),
        );

        return
            !$isLiquidationOnSafeDistance
                ? TradingCheckResult::failed($this, StopCheckFailureEnum::FurtherMainPositionLiquidationIsTooClose, $info)
                : TradingCheckResult::succeed($this, $info)
        ;
    }

    private static function extractStopFromEntryDto(StopCheckDto $entryDto): Stop
    {
        return $entryDto->inner;
    }
}
