<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitByStopSteps;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Domain\Position\Helper\InitialMarginHelper;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Stop\StopsCollection;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\PriceDistanceSelector as Length;
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
    const float IM_PERCENT_RATIO_THRESHOLD = 1.5;

    public function __construct(
        private TradingParametersProviderInterface $parameters,
        private ApplyStopsToPositionHandler $applyStopsToHandler,
        private StopRepositoryInterface $stopRepository,
        private PositionInfoProviderInterface $positionInfoProvider,
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

    private function applyStep(LockInProfitEntry $entry, LinpByStopsGridStep $step): void
    {
        if (!$step->gridsDefinition) {
            throw new InvalidArgumentException('Grid must be specified');
        }

        $stepAlias = $step->stepAlias;
        $position = $entry->position;
        $symbol = $position->symbol;
        $positionSide = $position->side;

        $existedStops = $this->stopRepository->getByLockInProfitStepAlias($symbol, $positionSide, $stepAlias);

        $imRatio = $this->positionInfoProvider->getRealInitialMarginToTotalContractBalanceRatio($position);
        if ($imRatio->value() < self::IM_PERCENT_RATIO_THRESHOLD) {
            if ($existedStops) {
                $collection = new StopsCollection(...$existedStops)->filterWithCallback(static fn(Stop $stop) => !$stop->isOrderPushedToExchange());
                $this->stopRepository->remove(...$collection->getItems());
                OutputHelper::print(sprintf('remove existed stops for %s ($im%%Ratio %s < %s%%)', $position, $imRatio, self::IM_PERCENT_RATIO_THRESHOLD));
            }
            return;
        }

        $stopDistancePricePct = $this->parameters->transformLengthToPricePercent($symbol, $step->checkOnPriceLength);
        $absoluteLength = $stopDistancePricePct->of($position->entryPrice);

        $triggerOnPrice = $positionSide->isShort() ? $position->entryPrice()->value() - $absoluteLength : $position->entryPrice()->value() + $absoluteLength;
        // @todo | linp | insteadof <=0 always use some distance from 0
        if ($triggerOnPrice <= 0) {
            $triggerOnPrice = 0 + $this->parameters->transformLengthToPricePercent($symbol, PriceDistanceSelector::Standard)->of($position->entryPrice);
        }

        $triggerOnPrice = $symbol->makePrice($triggerOnPrice);

        $applicable = $entry->currentMarkPrice->isPriceOverTakeProfit($positionSide, $triggerOnPrice->value());
        if (!$applicable) {
            return;
        }

        $ordersGridDefinition = $this->parseGrid($positionSide, $symbol, $triggerOnPrice, $step->gridsDefinition);

        // @todo | lockInProfit | not only active. executed too?
        if ($existedStops) {
            $collection = new StopsCollection(...$existedStops);

            // @todo | lockInProfit | stops | mb use initial position size?
            $percent = $ordersGridDefinition->definedPercent->sub($collection->volumePart($position->getNotCoveredSize()));
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
                $position->getNotCoveredSize(),
                new OrdersGridDefinitionCollection($symbol, 'from strategy', $ordersGridDefinition),
                [
                    Stop::LOCK_IN_PROFIT_STEP_ALIAS => $stepAlias,
                    Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT => true,
                ]
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

    private static function print(string $message): void
    {
        OutputHelper::print(sprintf('%s: %s', OutputHelper::shortClassName(self::class), $message));
    }
}
