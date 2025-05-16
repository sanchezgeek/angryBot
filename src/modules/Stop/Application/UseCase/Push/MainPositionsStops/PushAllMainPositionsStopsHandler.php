<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\Push\MainPositionsStops;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParameters;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStops;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\Dto\FindStopsDto;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Value\CachedValue;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PushAllMainPositionsStopsHandler
{
    public function __invoke(PushAllMainPositionsStops $message): void
    {
        $start = OutputHelper::currentTimePoint();
        // @todo save cahce (e.g. for check) ... or have no sense? (context->currPosition already fresh)
        $positions = $this->positionService->getPositionsWithLiquidation();
        /** @var Position[] $positions */
        $positions = array_combine(
            array_map(static fn(Position $position) => $position->symbol->value, $positions),
            $positions
        );
        $lastMarkPrices = $this->positionService->getLastMarkPrices();

        $positionsCache = [];
        $queryInput = [];
        foreach ($positions as $position) {
            $queryInput[] = new FindStopsDto($position->symbol, $position->side, $lastMarkPrices[$position->symbol->value]);
            $positionsCache[$position->symbol->value] = new CachedValue(static fn() => throw new RuntimeException('Not implemented'), 3000, $position);
        }

        $stopsToSymbolsMap = [];
        foreach ($this->stopRepository->findAllActive($queryInput) as $stop) {
            $symbolRaw = $stop->getSymbol()->value;
            $stopsToSymbolsMap[$symbolRaw][] = $stop;
        }

        $sort = [];
        foreach ($positions as $position) {
            $positionSymbol = $position->symbol;
            $symbolRaw = $positionSymbol->value;
            $possibleTriggeredStops = $stopsToSymbolsMap[$symbolRaw] ?? [];
            $currentPrice = $lastMarkPrices[$positionSymbol->value];
            $ticker = new Ticker($positionSymbol, $currentPrice, $currentPrice, $currentPrice);
            $liquidationParameters = new LiquidationDynamicParameters(settingsProvider: $this->settingsProvider, position: $position, ticker: $ticker);
            $warningRange = $liquidationParameters->warningRange();
            $passedDistancePart = 0;
            if ($ticker->markPrice->isPriceInRange($warningRange)) {
                $priceDeltaWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
                $initialDistanceWithLiquidation = $liquidationParameters->warningDistance();
                $passedDistancePart = 1 - $priceDeltaWithLiquidation / $initialDistanceWithLiquidation;
            }

            $sort[$symbolRaw] = sprintf(
                'passedDistancePart_%.2f_im_%s_activatedStops_%d_%s',
                $passedDistancePart,
                $position->initialMargin->value(),
                count($possibleTriggeredStops),
                $symbolRaw
            );
        }

        $sort = array_flip($sort);
//        var_dump($sort);
        krsort($sort);
//        var_dump($sort);

        foreach ($sort as $symbolRaw) {
            $symbol = Symbol::from($symbolRaw);

            try {
                $positionState = $positionsCache[$symbol->value]->get();
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'Not implemented') {
//                    OutputHelper::block(sprintf('%s: slow cache', OutputHelper::shortClassName($this)), $e->getMessage(), $symbol->value);
                    $positionState = null; // if not in warn/crit
                    // and get without cache if in crit/warn
                } else {
                    throw $e;
                }
            }

            $message = new PushStops($symbol, $positions[$symbol->value]->side, $positionState);

            $this->innerHandler->__invoke($message);
        }

        OutputHelper::printTimeDiff(sprintf('%s: from begin to end', OutputHelper::shortClassName($this)), $start);
    }

    public function __construct(
        private AppSettingsProviderInterface $settingsProvider,
        private ByBitLinearPositionService $positionService,
        private StopRepository $stopRepository,
        private PushStopsHandler $innerHandler,
    ) {
    }
}
