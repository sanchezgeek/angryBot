<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\Push\MainPositionsStops;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParametersFactoryInterface;
use App\Bot\Application\Messenger\Job\Cache\UpdateTicker;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStops;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler;
use App\Bot\Application\Settings\PushStopSettings;
use App\Bot\Domain\Repository\Dto\FindStopsDto;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Settings\Application\Helper\SettingsHelper;
use App\Value\CachedValue;
use DateInterval;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * @see \App\Tests\Functional\Modules\Stop\Applicaiton\UseCase\Push\PushMainPositionsStopsTest
 */
#[AsMessageHandler]
final readonly class PushAllMainPositionsStopsHandler
{
    public const int TICKERS_MILLI_TTL = 2000;
    public const int TICKERS_IN_CHUNK = 4;

    public function __invoke(PushAllMainPositionsStops $message): void
    {
        $start = OutputHelper::currentTimePoint(); // $profilingContext = ProfilingContext::create(sprintf('AllMainStops_%s', date_create_immutable()->format('H:i:s'))); ProfilingPointsStaticCollector::addPoint(ProfilingPointDto::create('start iteration', $profilingContext));
        $this->warmupTickers();

        if (!$positions = $this->positionService->getPositionsWithLiquidation()) {
            return;
        }
        $lastMarkPrices = $this->positionService->getLastMarkPrices();

        $positionsCache = [];
        $queryInput = [];
        foreach ($positions as $position) {
            $currentPrice = $lastMarkPrices[$position->symbol->name()];

            // @todo get last diff between mark and index
            // add some offset as if it situation when indexPrice leans above markPrice
//            $offset = $position->symbol->minimalPriceMove() * 100 * 10;
//            $currentPrice = $position->isShort() ? $currentPrice->add($offset) : $currentPrice->sub($offset);

            $queryInput[] = new FindStopsDto($position->symbol, $position->side, $currentPrice);
            $positionsCache[$position->symbol->name()] = new CachedValue(static fn() => throw new RuntimeException('Not implemented'), 2500, $position);
        }

        $stopsToSymbolsMap = [];
        foreach ($this->stopRepository->findAllActive($queryInput) as $stops) {
            $stopsToSymbolsMap[$stops['symbol']][] = $stops;
        }

        $filterPositionsWithoutStops = SettingsHelper::exact(PushStopSettings::MainPositions_InitialFilter_If_StopsNotFound) === true;
        $foundStopsForSymbols = array_keys($stopsToSymbolsMap);

        $sort = [];
        foreach ($positions as $position) {
            $positionSymbol = $position->symbol;
            $symbolRaw = $positionSymbol->name();
            $possibleTriggeredStops = $stopsToSymbolsMap[$symbolRaw] ?? [];
            $currentPrice = $lastMarkPrices[$positionSymbol->name()];
            $ticker = new Ticker($positionSymbol, $currentPrice, $currentPrice, $currentPrice);
            $liquidationParameters = $this->liquidationDynamicParametersFactory->fakeWithoutHandledMessage($position, $ticker);
            $warningRange = $liquidationParameters->warningRange();
            $passedDistancePart = 0;
            if ($ticker->markPrice->isPriceInRange($warningRange)) {
                $priceDeltaWithLiquidation = $position->priceDistanceWithLiquidation($ticker);
                $initialDistanceWithLiquidation = $liquidationParameters->warningDistance();
                $passedDistancePart = 1 - $priceDeltaWithLiquidation / $initialDistanceWithLiquidation;
            }

            $k = $position->leverage->value() / 100;
            $im = $position->initialMargin->value() * $k;

            $sort[$symbolRaw] = [
                'passedDistancePart' => $passedDistancePart,
                'im' => $im,
                'activatedStops' => count($possibleTriggeredStops),
                'symbol' => $symbolRaw,
            ];
        }

        $sort = self::arrayOrder($sort, 'passedDistancePart', SORT_DESC, 'im', SORT_DESC, 'activatedStops', SORT_DESC);
        $sort = array_keys($sort);

        $lastSort = [];
        foreach ($sort as $symbolRaw) {
            $position = $positions[$symbolRaw];
            $lastSort[] = $symbol = $position->symbol;

            if ($filterPositionsWithoutStops && !in_array($symbolRaw, $foundStopsForSymbols, true)) {
                continue;
            }

            try {
                $positionState = $positionsCache[$symbolRaw]->get();
            } catch (RuntimeException) {
                $positionState = null; // @todo | all-main | if not in warn/crit // and get without cache if in crit/warn
            }

            $this->doHandle(new PushStops($symbol, $position->side, $positionState)); // $profilingContext->registerNewPoint(sprintf('dispatch PushStops for "%s %s"', $symbol->name(), $side->title()));
        }
        $this->lastSortStorage->setLastSort($lastSort);

        self::timeDiffInfo($start); // $profilingContext->registerNewPoint($spendTimeMsg);
    }

    private function doHandle(PushStops $dto): void
    {
        try {
            $this->innerHandler->__invoke($dto);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'current position is zero')) {
                OutputHelper::print(sprintf('%s %s position closed', $dto->symbol->name(), $dto->side->value));
            }

            throw $e;
        }
    }

    private function warmupTickers(): void
    {
        if ($lastSort = $this->lastSortStorage->getLastSort()) {
            /** @see bot-consumers.ini -> [program:tickers_updater_async] -> numprocs=4 */
            $chunkQnt = self::TICKERS_IN_CHUNK;

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

    private static function arrayOrder()
    {
        $args = func_get_args();
        $data = array_shift($args);
        foreach ($args as $n => $field) {
            if (is_string($field)) {
                $tmp = array_map(static fn ($row) => $row[$field], $data);
                $args[$n] = $tmp;
            }
        }
        $args[] = &$data;
        call_user_func_array('array_multisort', $args);
        return array_pop($args);
    }

    private static function timeDiffInfo(float $startPoint, ?string $desc = null): void
    {
        OutputHelper::printTimeDiff(sprintf('PushMainStops%s', $desc ? sprintf(': %s', $desc) : ''), $startPoint);
    }

    public function __construct(
        private MainStopsPushLastSortStorage $lastSortStorage,
        private MessageBusInterface $messageBus,
        private ByBitLinearPositionService $positionService,
        private StopRepository $stopRepository,
        private PushStopsHandler $innerHandler,
        private LiquidationDynamicParametersFactoryInterface $liquidationDynamicParametersFactory,
    ) {
    }
}
