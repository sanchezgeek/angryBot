<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidation;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Mixin\SandboxExecutionAwareTrait;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Ticker;
use App\Domain\Price\Price;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\OutputHelper;
use App\Liquidation\Domain\Assert\LiquidationIsSafeAssertion;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\AbstractStopCheck;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopCheckResult;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopChecksContext;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Exception\TooManyTriesForCheckStop;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Mixin\CheckBasedOnCurrentPositionState;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Mixin\CheckBasedOnExecutionInSandbox;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;

/**
 * @see \App\Tests\Unit\Modules\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidationCheckTest
 */
final class FurtherMainPositionLiquidationCheck extends AbstractStopCheck
{
    use SandboxExecutionAwareTrait;
    use CheckBasedOnExecutionInSandbox;
    use CheckBasedOnCurrentPositionState;

    private const CACHE_RESET_INTERVAL = 45;

    /** @var StopCheckResult[]  */
    private array $cache = [];
    private int $cacheSavedAt;

    public function __construct(
        private readonly FurtherMainPositionLiquidationCheckParametersInterface $parameters,
        private readonly RateLimiterFactory $checkCanCloseSupportWhilePushStopsThrottlingLimiter,
        PositionServiceInterface $positionService,
        TradingSandboxFactoryInterface $sandboxFactory,
        SandboxStateFactoryInterface $sandboxStateFactory,
    ) {
        $this->initSandboxServices($sandboxFactory, $sandboxStateFactory);
        $this->initPositionService($positionService);

        $this->cacheSavedAt = time();
    }

    public function supports(Stop $stop, StopChecksContext $context): bool
    {
        $this->enrichContextWithCurrentPositionState($stop, $context);

        return $context->currentPositionState->isSupportPosition();
    }

    public function check(Stop $stop, StopChecksContext $context): StopCheckResult
    {
        if (!$this->checkCanCloseSupportWhilePushStopsThrottlingLimiter->create((string)$stop->getId())->consume()->isAccepted()) {
            throw new TooManyTriesForCheckStop(sprintf('Too many tries for "%s" check (Stop.id = %d)', OutputHelper::shortClassName(__CLASS__), $stop->getId()));
        }

        $this->checkCacheReset();

        return $this->cached($stop, $context);
    }

    /**
     * @throws UnexpectedSandboxExecutionException
     */
    private function cached(Stop $stop, StopChecksContext $context): StopCheckResult
    {
        $range = 2;
        $tickerPrice = self::realStopExecutionPrice($context->ticker, $stop);
        $step = PnlHelper::convertPnlPercentOnPriceToAbsDelta(10, $tickerPrice);
        $atStep = floor($tickerPrice->value() / $step);

        // @todo interesting coincidence...
        $currentCacheKey = self::resulCacheKey($atStep, $stop);
        for ($i = $atStep - $range; $i <= $atStep + $range; $i++) {
            $cacheKey = self::resulCacheKey($i, $stop);
            if (
                ($cachedResult = $this->cache[$cacheKey] ?? null)
                && !$cachedResult->success // only for negative results
            ) {
//                OutputHelper::warning(sprintf('hit: %s vs prev %s while check stop.id=%s (%s %s)', $currentCacheKey, $cacheKey, $stop->getId(), $context->ticker->symbol->value, $context->ticker->markPrice->value()));
                return $cachedResult->resetReason();
            }
        }

        $this->cache[$currentCacheKey] = $result = $this->doCheck($stop, $context);

        return $result;
    }

    /**
     * @throws UnexpectedSandboxExecutionException
     */
    public function doCheck(Stop $stop, StopChecksContext $context): StopCheckResult
    {
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

        $ticker = $context->ticker;
        $executionPrice = self::realStopExecutionPrice($ticker, $stop);

        // @todo | liquidation | null
        if ($mainPositionLiquidationPriceNew->eq(0)) {
            return self::positiveResult(
                sprintf('%s | id=%d, qty=%s, price=%s | liquidation=%s', $closingPosition, $stop->getId(), $stop->getVolume(), $executionPrice, $mainPositionLiquidationPriceNew)
            );
        }

        $tickerPrice = $ticker->markPrice;
        // @todo separated strategy if support in loss / main not in loss (select price between ticker and entry / or add distance between support and ticker)
        $withPrice = $mainPosition->isPositionInLoss($tickerPrice) ? $tickerPrice : $mainPosition->entryPrice();
        $safeDistance = $this->parameters->mainPositionSafeLiquidationPriceDelta($mainPosition->symbol, $mainPosition->side, $withPrice->value());
        $isLiquidationOnSafeDistance = LiquidationIsSafeAssertion::assert($mainPosition->side, $mainPositionLiquidationPriceNew, $withPrice, $safeDistance);

        $reason = sprintf(
            '%s | id=%d, qty=%s, price=%s | safeDistance=%s, liquidation=%s',
            $closingPosition, $stop->getId(), $stop->getVolume(), $executionPrice, $safeDistance, $mainPositionLiquidationPriceNew
        );

        return !$isLiquidationOnSafeDistance ? self::negativeResult($reason) : self::positiveResult($reason);
    }

    private static function realStopExecutionPrice(Ticker $ticker, Stop $stop): Price
    {
        return $stop->isCloseByMarketContextSet() ? $ticker->markPrice : $ticker->symbol->makePrice($stop->getPrice());
    }

    private static function resulCacheKey(float $atTickerPriceStep, Stop $stop): string
    {
        return sprintf('%s_%s', $atTickerPriceStep, $stop->getVolume());
    }

    public function checkCacheReset(): void
    {
        if (time() >= $this->cacheSavedAt + self::CACHE_RESET_INTERVAL) {
            $this->cache = [];
            $this->cacheSavedAt = time();
        }
//        if ($this->iterationsPassed >= self::RESET_CACHE_ITERATIONS_COUNT) {$this->cache = [];$this->iterationsPassed = 0;}$this->iterationsPassed++;
    }
}
