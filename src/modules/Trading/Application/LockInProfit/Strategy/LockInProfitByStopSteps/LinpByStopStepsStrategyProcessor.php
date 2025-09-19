<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitByStopSteps;

use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\DenyBuyIfFixationsExists;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\PriceRange;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Stop\StopsCollection;
use App\Domain\Trading\Enum\PriceDistanceSelector as Length;
use App\Domain\Value\Percent\Percent;
use App\Helper\NumberHelper;
use App\Helper\OutputHelper;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionEntryDto;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionHandler;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByStopSteps\Step\LinpByStopsGridStep;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitStrategyProcessorInterface;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\UseCase\OpenPosition\OrdersGrids\OpenPositionStopsGridsDefinitions;
use App\Trading\Contract\LockInProfit\LockInProfitEntry;
use App\Trading\Contract\PositionInfoProviderInterface;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinition;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinitionCollection;
use App\Trading\Domain\Symbol\SymbolInterface;
use InvalidArgumentException;

final readonly class LinpByStopStepsStrategyProcessor implements LockInProfitStrategyProcessorInterface
{
    private const float IM_PERCENT_RATIO_THRESHOLD = 1;
    private const float IM_PERCENT_RATIO_TO_USE_CALC_MULTIPLIER = 2;

    public function __construct(
        private TradingParametersProviderInterface $parameters,
        private ApplyStopsToPositionHandler $applyStopsToHandler,
        private StopRepositoryInterface $stopRepository,
        private PositionInfoProviderInterface $positionInfoProvider,
        private StopServiceInterface $stopService,
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

        foreach ($dto->steps as $key => $step) {
            $this->applyStep($entry, $step);
        }
    }

    public function closingPartMultiplier(Position $position, ?Percent $imRatio = null): Percent
    {
        $imRatio = $imRatio ?? $this->positionInfoProvider->getRealInitialMarginToTotalContractBalanceRatio($position->symbol, $position);

        $part = $imRatio->value() / self::IM_PERCENT_RATIO_TO_USE_CALC_MULTIPLIER;

        return Percent::fromPart(NumberHelper::minMax($part, 0.3, 1));
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

        // @todo | lockInProfit | not only active. executed too?
        $existedStops = $this->stopRepository->getByLockInProfitStepAlias($symbol, $positionSide, $stepAlias);
        $stepIsApplicable = $entry->currentMarkPrice->isPriceOverTakeProfit($positionSide, $triggerOnPrice->value());

        $imRatio = $this->positionInfoProvider->getRealInitialMarginToTotalContractBalanceRatio($symbol, $position);
        if ($imRatio->value() < self::IM_PERCENT_RATIO_THRESHOLD) {
            if ($existedStops) {
                $existedStopsCollection = new StopsCollection(...$existedStops)->filterWithCallback(static fn(Stop $stop) => !$stop->isOrderPushedToExchange());

                $this->stopRepository->remove(...$existedStopsCollection->getItems());
                OutputHelper::print(sprintf('remove existed stops for %s ($im%%Ratio %s < %s%%, step = %s)', $position, $imRatio, self::IM_PERCENT_RATIO_THRESHOLD, $stepAlias));
            }

            if ($stepIsApplicable) {
                /**
                 * create minimal order to prevent buy if step actual
                 * @see DenyBuyIfFixationsExists::getFixationStopsCountBeforePrice
                 */
                $this->stopService->create(
                    $symbol,
                    $positionSide,
                    PriceRange::create($entry->currentMarkPrice, $position->entryPrice(), $symbol)->getMiddlePrice(),
                    $symbol->minOrderQty(),
                    null,
                    $this->stepStopsContext($step),
                );
            }

            return;
        }

        if (!$stepIsApplicable) {
            return;
        }

        $positionSizeToCover = $this->closingPartMultiplier($position, $imRatio)->of($position->getNotCoveredSize());
        $ordersGridDefinition = $this->parseGrid($positionSide, $symbol, $triggerOnPrice, $step->gridsDefinition);

        if ($existedStops) {
            $existedStopsCollection = new StopsCollection(...$existedStops);

            // @todo | lockInProfit | stops | mb use initial position size?
            $percent = $ordersGridDefinition->definedPercent->sub($existedStopsCollection->volumePart($positionSizeToCover));
            if ($percent->value() <= 0) {
                return;
            }

            $ordersGridDefinition = new OrdersGridDefinition(
                $ordersGridDefinition->priceRange,
                $percent,
                $ordersGridDefinition->ordersCount,
                $ordersGridDefinition->contextsDefs
            );
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

    /**
     * Move somewhere + use for buy/stop grids on open
     */
    private function parseGrid(Side $positionSide, SymbolInterface $symbol, SymbolPrice $refPrice, string $definition): OrdersGridDefinition
    {
        $arr = explode('|', $definition);
        $range = array_shift($arr);
        [$from, $to] = explode('..', $range);

        $fromPnlPercent = $this->parseFromPnlPercent($symbol, $from);
        $toPnlPercent = $this->parseFromPnlPercent($symbol, $to);

        $stringDef = sprintf('%.2f%%..%.2f%%|%s', $fromPnlPercent, $toPnlPercent, implode('|', $arr));

        return OrdersGridDefinition::create($stringDef, $refPrice, $positionSide, $symbol);
    }

    /**
     * @see OpenPositionStopsGridsDefinitions::parseFromPnlPercent DRY
     */
    private function parseFromPnlPercent(SymbolInterface $symbol, null|float|string $fromPnlPercent): float
    {
        $fromPnlPercent = $fromPnlPercent ?? 0;

        if (is_string($fromPnlPercent)) {
            [$distance, $sign] = OpenPositionStopsGridsDefinitions::parseDistanceSelector($fromPnlPercent);
            $fromPnlPercent = $sign * $this->getBoundPnlPercent($symbol, $distance);
        }

        return $fromPnlPercent;
    }

    private function getBoundPnlPercent(SymbolInterface $symbol, Length $lengthSelector): float
    {
        $priceChangePercent = $this->parameters->transformLengthToPricePercent($symbol, $lengthSelector)->value();

        return PnlHelper::transformPriceChangeToPnlPercent($priceChangePercent);
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
