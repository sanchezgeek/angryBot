<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Strategy\LockInProfitBySteps;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Stop\StopsCollection;
use App\Domain\Trading\Enum\PriceDistanceSelector as Length;
use App\Domain\Trading\Enum\TradingStyle;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionEntryDto;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionHandler;
use App\Trading\Application\LockInProfit\Strategy\LockInpProfitStrategyInterface;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\UseCase\OpenPosition\OrdersGrids\OpenPositionStopsGridsDefinitions;
use App\Trading\Contract\LockInProfit\LockInProfitEntry;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinition;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinitionCollection;
use App\Trading\Domain\Symbol\SymbolInterface;
use InvalidArgumentException;

final readonly class LockInProfitByStepsStrategy implements LockInpProfitStrategyInterface
{
    public function __construct(
        private TradingParametersProviderInterface $parameters,
        private ApplyStopsToPositionHandler $applyStopsToHandler,
        private ExchangeServiceInterface $exchangeService,
        private StopRepositoryInterface $stopRepository,
    ) {
    }

    public function supports(LockInProfitEntry $entry): bool
    {
        return $entry->innerStrategyDto instanceof LockInProfitByStepsInnerDto;
    }

    public function process(LockInProfitEntry $entry): void
    {
        /** @var LockInProfitByStepsInnerDto $dto */
        $dto = $entry->innerStrategyDto;

        foreach ($dto->steps as $key => $step) {
            $this->applyStep($entry, $step);
        }
    }

    private function applyStep(LockInProfitEntry $entry, LockInProfitByStepDto $step): void
    {
        if (!$step->gridsDefinition) {
            throw new InvalidArgumentException('Grid must be specified');
        }

        $stepAlias = $step->alias;
        $position = $entry->position;
        $symbol = $position->symbol;
        $positionSide = $position->side;

        $stopDistancePricePct = $this->parameters->stopLength($symbol, $step->checkOnPriceLength);
        $absoluteLength = $stopDistancePricePct->of($position->entryPrice);

        $triggerOnPrice = $positionSide->isShort() ? $position->entryPrice()->sub($absoluteLength) : $position->entryPrice()->add($absoluteLength);

        $ticker = $this->exchangeService->ticker($symbol);

        $applicable = $ticker->markPrice->isPriceOverTakeProfit($positionSide, $triggerOnPrice->value());
        if (!$applicable) {
            return;
        }

        $ordersGridDefinition = $this->parseGrid($positionSide, $symbol, $triggerOnPrice, $step->gridsDefinition);

        if ($existedStops = $this->stopRepository->getByLockInProfitStepAlias($symbol, $positionSide, $stepAlias)) {
            $collection = new StopsCollection(...$existedStops);

            $percent = $ordersGridDefinition->definedPercent->sub($collection->volumePart($position->size), false);
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
                $position->size,
                new OrdersGridDefinitionCollection($symbol, 'from strategy', $ordersGridDefinition),
                [
                    Stop::LOCK_IN_PROFIT_STEP_ALIAS => $stepAlias,
                    Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT => true,
                ]
            )
        );
    }

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

    public static function makeGridDefinition(
        string $from,
        string $to,
        float $positionSizePercent,
        int $stopsCount,
    ): string {
        return sprintf('%s..%s|%d%%|%s', $from, $to, $positionSizePercent, $stopsCount);
    }

    public static function defaultThreeStepsLock(?TradingStyle $tradingStyle = null): LockInProfitByStepsInnerDto
    {
        // add trading style to alias (for further recreate after style changed)
        return new LockInProfitByStepsInnerDto(
            [
                // first part
                new LockInProfitByStepDto(
                    'defaultThreeStepsLock-part1',
                    Length::Standard, // settings?
                    self::makeGridDefinition(Length::Short->toStringWithNegativeSign(), Length::ModerateLong->toStringWithNegativeSign(), 15, 10),
                ),
                // second part
                new LockInProfitByStepDto(
                    'defaultThreeStepsLock-part2',
                    Length::Long,
                    self::makeGridDefinition(Length::Short->toStringWithNegativeSign(), Length::ModerateLong->toStringWithNegativeSign(), 15, 10),
                ),
                // third part: back to position entry
                new LockInProfitByStepDto(
                    'defaultThreeStepsLock-part3',
                    Length::VeryVeryLong,
                    self::makeGridDefinition(Length::Short->toStringWithNegativeSign(), Length::VeryVeryLong->toStringWithNegativeSign(), 30, 30),
                ),
            ]
        );
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
        $priceChangePercent = $this->parameters->stopLength($symbol, $lengthSelector)->value();

        return PnlHelper::transformPriceChangeToPnlPercent($priceChangePercent);
    }
}
