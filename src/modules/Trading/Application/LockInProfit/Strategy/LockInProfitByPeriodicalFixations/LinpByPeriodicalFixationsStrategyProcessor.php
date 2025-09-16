<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations;

use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Helper\DateTimeHelper;
use App\Helper\OutputHelper;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\State\LockInProfitPeriodicalFixationsStorageInterface;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\State\PeriodicalFixationStepState;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\Step\PeriodicalFixationStep;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitStrategyProcessorInterface;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Contract\LockInProfit\LockInProfitEntry;

final readonly class LinpByPeriodicalFixationsStrategyProcessor implements LockInProfitStrategyProcessorInterface
{
    public function __construct(
        private TradingParametersProviderInterface $parameters,
        private LockInProfitPeriodicalFixationsStorageInterface $storage,
        private OrderServiceInterface $orderService,
    ) {
    }

    public function supports(LockInProfitEntry $entry): bool
    {
        return $entry->innerStrategyDto instanceof LinpByPeriodicalFixationsStrategyDto;
    }

    public function process(LockInProfitEntry $entry): void
    {
        /** @var LinpByPeriodicalFixationsStrategyDto $dto */
        $dto = $entry->innerStrategyDto;

        $steps = array_reverse($dto->steps); // or sort by length
        foreach ($steps as $step) {
            $applied = $this->applyStep($entry, $step);

            if ($applied && !$step->mustPreviousBeApplied) {
                break;
            }
        }
    }

    private function applyStep(LockInProfitEntry $entry, PeriodicalFixationStep $step): bool
    {
        $position = $entry->position;
        $symbol = $position->symbol;
        $positionSide = $position->side;

        $state = $this->storage->getState($symbol, $positionSide, $step);

        if (!$this->stepMustBeRun($step, $state)) {
            return false;
        }

        $stopDistancePricePct = $this->parameters->transformLengthToPricePercent($symbol, $step->applyAfterPriceLength);
        $absoluteLength = $stopDistancePricePct->of($position->entryPrice);
        $triggerOnPrice = $positionSide->isShort() ? $position->entryPrice()->sub($absoluteLength) : $position->entryPrice()->add($absoluteLength);

        $applicable = $entry->currentMarkPrice->isPriceOverTakeProfit($positionSide, $triggerOnPrice->value());
        if (!$applicable) {
            return false;
        }

        $initialPositionSize = $state?->initialPositionSize ?? $position->getNotCoveredSize();
        $alreadyClosedOnThisStep = $state?->totalClosed ?? 0;
        $maxPositionSizeToCloseOnThisStep = $step->maxPositionSizePart->of($initialPositionSize);

        if ($alreadyClosedOnThisStep >= $maxPositionSizeToCloseOnThisStep) {
            return false;
        }

        $closed = $this->orderService->closeByMarket(
            $position, $step->singleFixationPart->of($initialPositionSize)
        )->realClosedQty;

        $totalClosed = $alreadyClosedOnThisStep + $closed;
        $left = $maxPositionSizeToCloseOnThisStep - $totalClosed;

        self::print(sprintf('fix %s (%s left) on %s [%s]', $closed, $left, $position, $step->alias));

        $newState = new PeriodicalFixationStepState(
            $symbol,
            $positionSide,
            $step,
            DateTimeHelper::now(),
            $initialPositionSize,
            $totalClosed
        );

        $this->storage->saveState($newState);

        return true;
    }

    private static function print(string $message): void
    {
        OutputHelper::print(sprintf('%s: %s', OutputHelper::shortClassName(self::class), $message));
    }

    private function stepMustBeRun(PeriodicalFixationStep $step, ?PeriodicalFixationStepState $state): bool
    {
        if (!$state) {
            return true;
        }

        $secondsPassed = DateTimeHelper::dateIntervalToSeconds(DateTimeHelper::now()->diff($state->lastFixationDatetime));

        return $secondsPassed >= $step->secondsInterval;
    }
}
