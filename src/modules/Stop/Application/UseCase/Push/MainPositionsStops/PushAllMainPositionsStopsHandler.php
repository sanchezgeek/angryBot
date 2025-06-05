<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\Push\MainPositionsStops;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParameters;
use App\Bot\Application\Messenger\Job\Cache\UpdateTicker;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStops;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\Dto\FindStopsDto;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Value\CachedValue;
use DateInterval;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class PushAllMainPositionsStopsHandler
{
    public const int TICKERS_MILLI_TTL = 2000;

    public function __invoke(PushAllMainPositionsStops $message): void
    {
        $start = OutputHelper::currentTimePoint(); // $profilingContext = ProfilingContext::create(sprintf('AllMainStops_%s', date_create_immutable()->format('H:i:s'))); ProfilingPointsStaticCollector::addPoint(ProfilingPointDto::create('start iteration', $profilingContext));
        $this->warmupTickers();

        if (!$positions = $this->positionService->getPositionsWithLiquidation()) {
            return;
        }

        /** @var Position[] $positions */
        $positions = array_combine(
            array_map(static fn(Position $position) => $position->symbol->name(), $positions),
            $positions
        );
        $lastMarkPrices = $this->positionService->getLastMarkPrices();

        $positionsCache = [];
        $queryInput = [];
        foreach ($positions as $position) {
            $queryInput[] = new FindStopsDto($position->symbol, $position->side, $lastMarkPrices[$position->symbol->name()]);
            $positionsCache[$position->symbol->name()] = new CachedValue(static fn() => throw new RuntimeException('Not implemented'), 2500, $position);
        }

        $stopsToSymbolsMap = [];
        foreach ($this->stopRepository->findAllActive($queryInput) as $stop) {
            $stopsToSymbolsMap[$stop['symbol']][] = $stop;
        }

        $sort = [];
        foreach ($positions as $position) {
            $positionSymbol = $position->symbol;
            $symbolRaw = $positionSymbol->name();
            $possibleTriggeredStops = $stopsToSymbolsMap[$symbolRaw] ?? [];
            $currentPrice = $lastMarkPrices[$positionSymbol->name()];
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

        $lastSort = [];
        foreach ($sort as $symbolRaw) {
            $position = $positions[$symbolRaw];
            $lastSort[] = $symbol = $position->symbol;

            try {
                $positionState = $positionsCache[$symbolRaw]->get();
            } catch (RuntimeException) {
                $positionState = null; // @todo | all-main | if not in warn/crit // and get without cache if in crit/warn
            }

            $this->innerHandler->__invoke(new PushStops($symbol, $position->side, $positionState)); // $profilingContext->registerNewPoint(sprintf('dispatch PushStops for "%s %s"', $symbol->name(), $side->title()));
        }
        $this->lastSortStorage->setLastSort($lastSort);

        self::timeDiffInfo('from begin to end', $start); // $profilingContext->registerNewPoint($spendTimeMsg);
    }

    private function warmupTickers(): void
    {
        if ($lastSort = $this->lastSortStorage->getLastSort()) {
            /** @see bot-consumers.ini -> [program:tickers_updater_async] -> numprocs=4 */
            $chunkQnt = 3;

            $chunks = [];
            $chunkNumber = 0;
            while ($symbol = array_shift($lastSort)) {
                if ($chunkNumber === $chunkQnt) {
                    $chunkNumber = 0;
                }

                $chunks[$chunkNumber] = $chunks[$chunkNumber] ?? [];
                array_unshift($chunks[$chunkNumber], $symbol);
                $chunkNumber++;
            }

            $ttl = DateInterval::createFromDateString(sprintf('%d milliseconds', self::TICKERS_MILLI_TTL));
            foreach ($chunks as $chunk) {
                $reverse = array_reverse($chunk);
                $this->messageBus->dispatch(new UpdateTicker($ttl, ...$reverse));
            }
        }
    }

    private static function timeDiffInfo(string $desc, float $startPoint, bool $print = true): string
    {
        $diff = OutputHelper::timeDiff(sprintf('PushMainStops: %s', $desc), $startPoint);

        if ($print) {
            OutputHelper::print($diff);
        }

        return $diff;
    }

    public function __construct(
        private MainStopsPushLastSortStorage $lastSortStorage,
        private MessageBusInterface $messageBus,
        private AppSettingsProviderInterface $settingsProvider,
        private ByBitLinearPositionService $positionService,
        private StopRepository $stopRepository,
        private PushStopsHandler $innerHandler,
    ) {
    }
}
