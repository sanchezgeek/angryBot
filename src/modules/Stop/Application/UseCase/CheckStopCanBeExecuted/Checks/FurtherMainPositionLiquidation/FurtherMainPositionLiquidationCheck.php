<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidation;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Mixin\SandboxExecutionAwareTrait;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Helper\OutputHelper;
use App\Liquidation\Domain\Assert\LiquidationIsSafeAssertion;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopCheckResult;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopChecksContext;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Exception\TooManyTriesForCheckStop;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Mixin\CheckBasedOnCurrentPositionState;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Mixin\CheckBasedOnExecutionInSandbox;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\StopCheckInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;

final class FurtherMainPositionLiquidationCheck implements StopCheckInterface
{
    use SandboxExecutionAwareTrait;
    use CheckBasedOnExecutionInSandbox;
    use CheckBasedOnCurrentPositionState;

    public function __construct(
        private readonly FurtherMainPositionLiquidationCheckParametersInterface $parameters,
        private readonly RateLimiterFactory $checkCanCloseSupportWhilePushStopsThrottlingLimiter,
        PositionServiceInterface $positionService,
        TradingSandboxFactoryInterface $sandboxFactory,
        SandboxStateFactoryInterface $sandboxStateFactory,
    ) {
        $this->initSandboxServices($sandboxFactory, $sandboxStateFactory);
        $this->initPositionService($positionService);
    }

    public function supports(Stop $stop, StopChecksContext $context): bool
    {
        $this->enrichContextWithCurrentPositionState($stop, $context);

        return $context->currentPositionState->isSupportPosition();
    }

    public function check(Stop $stop, StopChecksContext $context): StopCheckResult
    {
        // cache
        if (!$this->checkCanCloseSupportWhilePushStopsThrottlingLimiter->create((string)$stop->getId())->consume()->isAccepted()) {
            throw new TooManyTriesForCheckStop(sprintf('Too many tries for "%s" check (Stop.id = %d)', OutputHelper::shortClassName(__CLASS__), $stop->getId()));
        }

        $this->enrichContextWithCurrentSandboxState($context);

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

        // @todo | liquidation | null
        if ($mainPositionLiquidationPriceNew->eq(0)) {
            return StopCheckResult::positive();
        }

        $tickerPrice = $context->ticker->markPrice;
        $safePriceDistance = $this->parameters->mainPositionSafeLiquidationPriceDelta($mainPosition->symbol, $tickerPrice);

        $isLiquidationOnSafeDistance = LiquidationIsSafeAssertion::assert(
            $mainPosition->side,
            $mainPositionLiquidationPriceNew,
            $tickerPrice,
            $safePriceDistance
        );

        if (!$isLiquidationOnSafeDistance) {
            return StopCheckResult::negative(
                __CLASS__,
                sprintf(
                    '[%s | %d | qty=%s, price=%s -> FurtherMainPositionLiquidation failed (safeDistance=%s,liquidation=%s)',
                    $closingPosition,
                    $stop->getId(),
                    $stop->getVolume(),
                    $stop->getPrice(),
                    $safePriceDistance,
                    $mainPositionLiquidationPriceNew
                )
            );
        }

        return StopCheckResult::positive();
    }
}
