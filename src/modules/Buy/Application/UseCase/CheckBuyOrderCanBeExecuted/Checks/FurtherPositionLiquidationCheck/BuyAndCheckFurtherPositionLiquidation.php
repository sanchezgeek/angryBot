<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\FurtherPositionLiquidationCheck;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxInsufficientAvailableBalanceException;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Mixin\SandboxExecutionAwareTrait;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\BuyCheckInterface;
use App\Helper\OutputHelper;
use App\Liquidation\Domain\Assert\LiquidationIsSafeAssertion;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopCheckResult;
use App\Trading\Application\Check\Contract\AbstractTradingCheckResult;
use App\Trading\Application\Check\Dto\TradingCheckContext;
use App\Trading\Application\Check\Dto\TradingCheckResult;
use App\Trading\Application\Check\Exception\TooManyTriesForCheck;
use App\Trading\Application\Check\Mixin\CheckBasedOnCurrentPositionState;
use App\Trading\Application\Check\Mixin\CheckBasedOnExecutionInSandbox;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;

/**
 * @see \App\Tests\Unit\Modules\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyAndCheckFurtherPositionLiquidationTest
 */
final class BuyAndCheckFurtherPositionLiquidation implements BuyCheckInterface
{
    use SandboxExecutionAwareTrait;
    use CheckBasedOnExecutionInSandbox;
    use CheckBasedOnCurrentPositionState;

    private const CACHE_RESET_INTERVAL = 45;

    /** @var StopCheckResult[]  */
    private array $cache = [];
    private int $cacheSavedAt;

    public function __construct(
        private readonly TradingParametersProviderInterface $parameters,
        private readonly RateLimiterFactory $checkFurtherPositionLiquidationAfterBuyLimiter,
        PositionServiceInterface $positionService,
        TradingSandboxFactoryInterface $sandboxFactory,
        SandboxStateFactoryInterface $sandboxStateFactory,
    ) {
        $this->initSandboxServices($sandboxFactory, $sandboxStateFactory);
        $this->initPositionService($positionService);

        $this->cacheSavedAt = time();
    }

    public function supports(MarketBuyEntryDto $dto, TradingCheckContext $context): bool
    {
        // position and order mismatch? => logic
        $this->enrichContextWithCurrentPositionState($dto->symbol, $dto->positionSide, $context);

        $symbol = $dto->symbol;
        $position = $context->currentPositionState;
        $checkMustBeSkipped = $dto->force;

        return
            !$checkMustBeSkipped && (
                $position->isPositionWithoutHedge()
                || $position->isMainPosition()
                || $symbol->roundVolume($position->size + $dto->volume) > $position->oppositePosition->size
            )
        ;
    }

    public function check(MarketBuyEntryDto $dto, TradingCheckContext $context): AbstractTradingCheckResult
    {
        if (
            !$context->withoutThrottling
            && $dto->sourceBuyOrder
            && !$this->checkFurtherPositionLiquidationAfterBuyLimiter->create((string)($orderId = $dto->sourceBuyOrder->getId()))->consume()->isAccepted()
        ) {
            throw new TooManyTriesForCheck(sprintf('Too many tries for "%s" check (BuyOrder.id = %d)', OutputHelper::shortClassName(__CLASS__), $orderId));
        }

//        $this->checkCacheReset();

        return $this->cached($dto, $context);
    }

    /**
     * @throws UnexpectedSandboxExecutionException
     */
    private function cached(MarketBuyEntryDto $dto, TradingCheckContext $context): AbstractTradingCheckResult
    {
//        $range = 2;
//        $tickerPrice = self::realStopExecutionPrice($context->ticker, $stop);
//        $step = PnlHelper::convertPnlPercentOnPriceToAbsDelta(10, $tickerPrice);
//        $atStep = floor($tickerPrice->value() / $step);
//
//        // @todo interesting coincidence...
//        $currentCacheKey = self::resulCacheKey($atStep, $stop);
//        for ($i = $atStep - $range; $i <= $atStep + $range; $i++) {
//            $cacheKey = self::resulCacheKey($i, $stop);
//            if (
//                ($cachedResult = $this->cache[$cacheKey] ?? null)
//                && !$cachedResult->success // only for negative results
//            ) {
////                OutputHelper::warning(sprintf('hit: %s vs prev %s while check stop.id=%s (%s %s)', $currentCacheKey, $cacheKey, $stop->getId(), $context->ticker->symbol->value, $context->ticker->markPrice->value()));
//                return $cachedResult->resetReason();
//            }
//        }

//        $this->cache[$currentCacheKey] =
        $result = $this->doCheck($dto, $context);

        return $result;
    }

    /**
     * @throws UnexpectedSandboxExecutionException
     */
    public function doCheck(MarketBuyEntryDto $order, TradingCheckContext $context): AbstractTradingCheckResult
    {
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

        return !$isLiquidationOnSafeDistance
            ? FurtherPositionLiquidationAfterBuyIsTooClose::create($this, $withPrice, $liquidationPrice, $safePriceDistance, $info)
            : TradingCheckResult::succeed($this, $info)
        ;
    }

    private static function resulCacheKey(float $atTickerPriceStep, Stop $stop): string
    {
        return sprintf('%s_%s', $atTickerPriceStep, $stop->getVolume());
    }

//    public function checkCacheReset(): void
//    {
//        if (time() >= $this->cacheSavedAt + self::CACHE_RESET_INTERVAL) {
//            $this->cache = [];
//            $this->cacheSavedAt = time();
//        }
////        if ($this->iterationsPassed >= self::RESET_CACHE_ITERATIONS_COUNT) {$this->cache = [];$this->iterationsPassed = 0;}$this->iterationsPassed++;
//    }
}
