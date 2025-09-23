<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitByStopSteps;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Domain\Stop\StopsCollection;
use App\Domain\Trading\Enum\PriceDistanceSelector as Length;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Helper\NumberHelper;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionEntryDto;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionHandler;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByStopSteps\Step\LinpByStopsGridStep;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitStrategyProcessorInterface;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Contract\LockInProfit\LockInProfitEntry;
use App\Trading\Contract\PositionInfoProviderInterface;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinition;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinitionCollection;
use App\Trading\Domain\Grid\Definition\OrdersGridTools;
use InvalidArgumentException;

/**
 * @see \App\Tests\Functional\Modules\Trading\Applicaiton\LockInProfit\Strategy\Processor\LinpByStopStepsStrategyProcessorTest
 */
final readonly class LinpByStopStepsStrategyProcessor implements LockInProfitStrategyProcessorInterface
{
    private const float BIG_IM_PERCENT_RATIO = 1.3;

    public function __construct(
        private TradingParametersProviderInterface $parameters,
        private ApplyStopsToPositionHandler $applyStopsToHandler,
        private StopRepositoryInterface $stopRepository,
        private PositionInfoProviderInterface $positionInfoProvider,
        private OrdersGridTools $ordersGridTools,
    ) {
    }

    public function supports(LockInProfitEntry $entry): bool
    {
        return $entry->innerStrategyDto instanceof LinpByStopStepsStrategyDto;
    }

    public function process(LockInProfitEntry $entry): void
    {
        /** @var LinpByStopStepsStrategyDto $dto */
        $dto = $entry->innerStrategyDto;

        foreach ($dto->steps as $step) {
            $this->applyStep($entry, $step);
        }
    }

    public function closingPartMultiplier(Position $position, ?Percent $imRatio = null): Percent
    {
        $imRatio = $imRatio ?? $this->positionInfoProvider->getRealInitialMarginToTotalContractBalanceRatio($position->symbol, $position);

        $part = $imRatio->value() / self::BIG_IM_PERCENT_RATIO;

        return Percent::fromPart(FloatHelper::round(NumberHelper::minMax($part, 0.2, 1)));
    }

    private function applyStep(LockInProfitEntry $entry, LinpByStopsGridStep $step): void
    {
        if (!$step->gridsDefinition) {
            throw new InvalidArgumentException('Grid must be specified');
        }

        $stepAlias = $step->stepAlias;
        $position = $entry->position;
        $symbol = $position->symbol;
        $positionSide = $position->side;

        $stopDistancePricePct = $this->parameters->transformLengthToPricePercent($symbol, $step->checkOnPriceLength);
        $absoluteLength = $stopDistancePricePct->of($position->entryPrice);

        $triggerOnPrice = $positionSide->isShort() ? $position->entryPrice()->value() - $absoluteLength : $position->entryPrice()->value() + $absoluteLength;
        // @todo | linp | insteadof <=0 always use some distance from 0
        if ($triggerOnPrice <= 0) {
            $triggerOnPrice = 0 + $this->parameters->transformLengthToPricePercent($symbol, Length::Standard)->of($position->entryPrice);
        }

        $triggerOnPrice = $symbol->makePrice($triggerOnPrice);

        $stepIsApplicable = $entry->currentMarkPrice->isPriceOverTakeProfit($positionSide, $triggerOnPrice->value());

        if (!$stepIsApplicable) {
            return;
        }

        $gridDefinition = $this->ordersGridTools->transformToFinalPercentRangeDefinition($symbol, $step->gridsDefinition);
        $ordersGridDefinition = OrdersGridDefinition::create($gridDefinition, $triggerOnPrice, $positionSide, $symbol);

        $imRatio = $this->positionInfoProvider->getRealInitialMarginToTotalContractBalanceRatio($symbol, $position);
        $multiplier = $this->closingPartMultiplier($position, $imRatio);
        $positionSizeToCover = $multiplier->of($position->getNotCoveredSize()); // @todo | lockInProfit | stops | mb use initial position size?

        // @todo | stop | isCloseByMarketContextSet | conditional orders broke this
        // @todo | lockInProfit | not only active. executed too?
        if ($existedStops = $this->stopRepository->getByLockInProfitStepAlias($symbol, $positionSide, $stepAlias)) {
            $existedNotExecutedStops = new StopsCollection(...$existedStops)->filterWithCallback(static fn(Stop $stop) => !$stop->isOrderPushedToExchange());
            $mustBeCoveredPercent = $ordersGridDefinition->definedPercent;
            $mustBeCoveredSize = $mustBeCoveredPercent->of($positionSizeToCover);
            $alreadyCoveredPercent = $existedNotExecutedStops->volumePart($mustBeCoveredSize);

            if ($alreadyCoveredPercent > $mustBeCoveredPercent->value()) {
                // recreate if covered part greater than must be covered
                $this->stopRepository->remove(...$existedNotExecutedStops->getItems());
            } else {
                $newPercent = $mustBeCoveredPercent->sub($alreadyCoveredPercent);
                $ordersGridDefinition = $ordersGridDefinition->cloneWithNewPercent($newPercent);
            }
        }

        $this->applyStopsToHandler->handle(
            new ApplyStopsToPositionEntryDto(
                $symbol,
                $positionSide,
                $positionSizeToCover,
                new OrdersGridDefinitionCollection($symbol, 'from strategy', $ordersGridDefinition),
                $this->stepStopsContext($step)
            )
        );
    }

//    private static function print(string $message): void
//    {
//        OutputHelper::print(sprintf('%s: %s', OutputHelper::shortClassName(self::class), $message));
//    }

    private function stepStopsContext(LinpByStopsGridStep $step): array
    {
        return [
            Stop::LOCK_IN_PROFIT_STEP_ALIAS => $step->stepAlias,
            Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT => true,
        ];
    }
}
