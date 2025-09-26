<?php

namespace App\Command\Position\OpenedPositions;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParametersFactoryInterface;
use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Clock\ClockInterface;
use App\Command\AbstractCommand;
use App\Command\Helper\ConsoleTableHelper as CTH;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Position\OpenedPositions\Cache\OpenedPositionsCache;
use App\Command\PositionDependentCommand;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\Helper\InitialMarginHelper;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\PriceRange;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Stop\StopsCollection;
use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\API\ConnectionHelper;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Infrastructure\Cache\PositionsCache;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use App\Output\Table\Dto\Cell;
use App\Output\Table\Dto\DataRow;
use App\Output\Table\Dto\SeparatorRow;
use App\Output\Table\Dto\Style\CellStyle;
use App\Output\Table\Dto\Style\Enum\CellAlign;
use App\Output\Table\Dto\Style\Enum\Color;
use App\Output\Table\Dto\Style\RowStyle;
use App\Output\Table\Formatter\ConsoleTableBuilder;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\TechnicalAnalysis\Application\Helper\TA;
use App\TechnicalAnalysis\Domain\Dto\HighLow\HighLowPrices;
use App\Trading\Domain\Symbol\Helper\SymbolHelper;
use App\Trading\Domain\Symbol\SymbolInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder as QB;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Throwable;

use function array_merge;
use function sprintf;

/**
 * @todo | UI | opened-positions | Capture specific position state to cache?
 * @todo | AllOpenedPositionsInfoCommand | stops found before ticker not executed (due to big mark/index difference)
 * @todo | AllOpenedPositionsInfoCommand | sort by changes
 */
#[AsCommand(name: 'p:opened')]
class AllOpenedPositionsInfoCommand extends AbstractCommand implements PositionDependentCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;

    private const string DEFAULT_UPDATE_INTERVAL = '15';

    private const string DEFAULT_SAVE_CACHE_INTERVAL = '150';

    private const string SortCacheKey = 'opened_positions_sort';
    private const string SavedDataKeysCacheKey = 'saved_data_cache_keys';
    private const string Manually_SavedDataKeysCacheKey = 'manually_saved_data_cache_keys';

    private const string SHOW_STOPS = 'show-stops';
    private const string MOVE_HEDGED_UP_OPTION = 'move-hedged-up';
    private const string WITH_SAVED_SORT_OPTION = 'sorted';
    private const string SORT_BY_OPTION = 'sort-by';
    private const string USE_INITIAL_MARGIN_FOR_SORT_OPTION = 'use-im-for-sort';
    private const string USE_LIQ_DISTANCE_FOR_SORT_OPTION = 'use-distance-for-sort'; // @todo | AllOpenedPositionsInfoCommand
    private const string SAVE_SORT_OPTION = 'save-sort';
    private const string FIRST_ITERATION_SAVE_CACHE_COMMENT = 'comment';
    private const string MOVE_UP_OPTION = 'move-up';
    private const string MOVE_DOWN_OPTION = 'move-down';
    private const string DIFF_WITH_SAVED_CACHE_OPTION = 'diff';
    private const string CURRENT_STATE_OPTION = 'current-state';
    private const string REMOVE_PREVIOUS_CACHE_OPTION = 'remove-prev';
    private const string SHOW_CACHE_OPTION = 'show-cache';
    private const string UPDATE_OPTION = 'update';
    private const string UPDATE_INTERVAL_OPTION = 'update-interval';
    private const string SAVE_EVERY_N_ITERATION_OPTION = 'save-cache-interval';
    private const string SHOW_FULL_TICKER_DATA_OPTION = 'show-full-ticker';

    private const string SHOW_SYMBOLS_OPTION = 'show-symbols';
    private const string HIDE_SYMBOLS_OPTION = 'hide-symbols';

    private bool $currentStateGonnaBeSaved = false;
    private array $cacheCollector = [];
    private ?string $showDiffWithOption;
    private ?string $cacheKeyToUseAsCurrentState;
    private bool $useSavedSort;
    private array $sortBy;
    private bool $useIMForSort;
    private bool $moveHedgedSymbolsUp;

    private ?array $savedRawSymbolsSort = null;
    private ?array $rawSymbolsSetToMoveUp = null;
    private ?array $rawSymbolsSetToMoveDown = null;

    /** @var SymbolInterface[] */
    private array $symbols;

    /** @var array<Position[]> */
    private array $positions;
    /** @var float[] */
    private array $ims = [];

    /** @var array<string, SymbolPrice> */
    private array $lastMarkPrices;

    private bool $currentSortSaved = false;

    protected function configure(): void
    {
        $this
            ->addOption(self::MOVE_HEDGED_UP_OPTION, null, InputOption::VALUE_NEGATABLE, 'Move fully-hedge positions up')
            ->addOption(self::WITH_SAVED_SORT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Apply saved sort')
            ->addOption(self::USE_INITIAL_MARGIN_FOR_SORT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Use initial margin for sort (asc)')
            ->addOption(self::SORT_BY_OPTION, null, InputOption::VALUE_OPTIONAL, 'Sort by (`im`, `watch`, `im,watch`)')
            ->addOption(self::SAVE_SORT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Save current sort')
            ->addOption(self::MOVE_UP_OPTION, null, InputOption::VALUE_OPTIONAL, 'Move specified symbols up')
            ->addOption(self::MOVE_DOWN_OPTION, null, InputOption::VALUE_OPTIONAL, 'Move specified symbols down')
            ->addOption(self::SHOW_SYMBOLS_OPTION, null, InputOption::VALUE_OPTIONAL, 'Show only specified symbols')
            ->addOption(self::HIDE_SYMBOLS_OPTION, null, InputOption::VALUE_OPTIONAL, 'Hide specified symbols')
            ->addOption(self::DIFF_WITH_SAVED_CACHE_OPTION, null, InputOption::VALUE_OPTIONAL, 'Output diff with saved cache')
            ->addOption(self::FIRST_ITERATION_SAVE_CACHE_COMMENT, 'c', InputOption::VALUE_OPTIONAL, 'Comment on first cache save')
            ->addOption(self::CURRENT_STATE_OPTION, null, InputOption::VALUE_OPTIONAL, 'Use specified cached data as current state')
            ->addOption(self::REMOVE_PREVIOUS_CACHE_OPTION, null, InputOption::VALUE_NEGATABLE, 'Remove previous cache')
            ->addOption(self::UPDATE_OPTION, null, InputOption::VALUE_NEGATABLE, 'Update?', true)
            ->addOption(self::UPDATE_INTERVAL_OPTION, null, InputOption::VALUE_REQUIRED, 'Update interval', self::DEFAULT_UPDATE_INTERVAL)
            ->addOption(self::SAVE_EVERY_N_ITERATION_OPTION, null, InputOption::VALUE_REQUIRED, 'Number of iterations to wait before save current state', self::DEFAULT_SAVE_CACHE_INTERVAL)
            ->addOption(self::SHOW_CACHE_OPTION, null, InputOption::VALUE_NEGATABLE, 'Show cache records?')
            ->addOption(self::SHOW_FULL_TICKER_DATA_OPTION, null, InputOption::VALUE_NEGATABLE, 'Show full ticker data?')
            ->addOption(self::SHOW_STOPS, null, InputOption::VALUE_NEGATABLE)
        ;
    }

    private array $showSymbolsRawValues = [];
    private array $hideSymbolsRawValues = [];
    private array $symbolsToWatch = [];
    private ?array $selectedCacheToShowDiffWith = null;
    private ?array $previousIterationCache = null;
    private string $bestWorstNote = '';
    private bool $showStops;

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->showDiffWithOption = $this->paramFetcher->getStringOption(self::DIFF_WITH_SAVED_CACHE_OPTION, false);
        $this->cacheKeyToUseAsCurrentState = $this->paramFetcher->getStringOption(self::CURRENT_STATE_OPTION, false);
        $this->useSavedSort = $this->paramFetcher->getBoolOption(self::WITH_SAVED_SORT_OPTION);
        $this->useIMForSort = $this->paramFetcher->getBoolOption(self::USE_INITIAL_MARGIN_FOR_SORT_OPTION);
        $this->sortBy = explode(',', $this->paramFetcher->getStringOption(self::SORT_BY_OPTION, false) ?? '');
        $this->savedRawSymbolsSort = ($item = $this->cache->getItem(self::SortCacheKey))->isHit() ? $item->get() : null;
        $this->moveHedgedSymbolsUp = $this->paramFetcher->getBoolOption(self::MOVE_HEDGED_UP_OPTION);
        $this->showStops = $this->paramFetcher->getBoolOption(self::SHOW_STOPS);

        if (
            ($moveUpOption = $this->paramFetcher->getStringOption(self::MOVE_UP_OPTION, false))
            && ($providedItems = $this->parseProvidedSymbols($moveUpOption))
        ) {
            $this->rawSymbolsSetToMoveUp = SymbolHelper::symbolsToRawValues(...$providedItems);
        }

        if (
            ($moveDownOption = $this->paramFetcher->getStringOption(self::MOVE_DOWN_OPTION, false))
            && ($providedItems = $this->parseProvidedSymbols($moveDownOption))
        ) {
            $this->rawSymbolsSetToMoveDown = SymbolHelper::symbolsToRawValues(...$providedItems);
        }

        if ($showSymbols = $this->paramFetcher->getStringOption(self::SHOW_SYMBOLS_OPTION, false)) {
            if ($providedItems = $this->parseProvidedSymbols($showSymbols)) {
                $this->showSymbolsRawValues = SymbolHelper::symbolsToRawValues(...$providedItems);
            }
        }

        if ($hideSymbols = $this->paramFetcher->getStringOption(self::HIDE_SYMBOLS_OPTION, false)) {
            if ($providedItems = $this->parseProvidedSymbols($hideSymbols)) {
                $this->hideSymbolsRawValues = SymbolHelper::symbolsToRawValues(...$providedItems);
            }
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CTH::registerColors($output);

        if ($this->paramFetcher->getBoolOption(self::SHOW_CACHE_OPTION)) {
            if (!$savedKeys = $this->getSavedDataCacheKeys()) {
                $this->io->error('Saved cache records not found'); return Command::FAILURE;
            }
            OutputHelper::block('Saved cache records:', $savedKeys); return Command::SUCCESS;
        }

        $previousIterationCache = null;
        $updateEnabled = $this->paramFetcher->getBoolOption(self::UPDATE_OPTION);
        $iteration = 0;
        do {
            try {
                echo '...';
                $iteration++;
                $this->cacheCollector = [];
                $this->selectedCacheToShowDiffWith = $cache = $this->getCacheRecordToShowDiffWith();
                $this->previousIterationCache = $prevCache = $previousIterationCache;
                $this->symbolsToWatch = $this->openedPositionsCache->getSymbolsToWatch();
                $cacheComment = $this->paramFetcher->getStringOption(self::FIRST_ITERATION_SAVE_CACHE_COMMENT, false);
                $saveCacheComment = $cacheComment && $iteration === 1;
                $this->currentStateGonnaBeSaved =
                    !$updateEnabled
                    || $saveCacheComment
                    || $iteration % $this->paramFetcher->getIntOption(self::SAVE_EVERY_N_ITERATION_OPTION) === 0;

                $this->doOut($cache, $prevCache);

                if ($this->currentStateGonnaBeSaved) {
                    $cachedDataCacheKey = sprintf('opened_positions_%s', $this->clock->now()->format('Y-m-d_H-i-s'));
                    if ($saveCacheComment) {
                        $cachedDataCacheKey .= '_' . $cacheComment;
                    }

                    $this->saveToCache($cachedDataCacheKey, $this->cacheCollector);
                    $this->addSavedDataCacheKey($cachedDataCacheKey);
                    if ($saveCacheComment) {
                        $this->addManuallySavedDataCacheKey($cachedDataCacheKey);
                    }
                    OutputHelper::print(sprintf('Cache saved as "%s"', $cachedDataCacheKey));
                }
                $previousIterationCache = $this->cacheCollector;
                $updateEnabled && sleep($this->paramFetcher->getIntOption(self::UPDATE_INTERVAL_OPTION));
            } catch (Throwable $exception) {
                if (
                    ConnectionHelper::isConnectionError($exception)
                    || str_contains($exception->getMessage(), 'Too many visits. Exceeded the API Rate Limit')
                ) {
                    echo 'reconnecting ... ' . PHP_EOL; sleep(5);
                } else {
                    throw $exception;
                }
            }
        } while ($updateEnabled);

        return Command::SUCCESS;
    }

    private function saveToCache(string $key, array $data): void
    {
        $item = $this->cache->getItem($key)->set($data)->expiresAfter(null);
        $this->cache->save($item);
    }

    private function addToCache(string $cacheKey, string $dataKey, mixed $data): void
    {
        $cacheItem = $this->cache->getItem($cacheKey);
        if ($cacheItem->isHit()) {
            $cache = $cacheItem->get();
            $cache[$dataKey] = $data;

            $this->saveToCache($cacheKey, $cache);
        }
    }

    public function doOut(?array $selectedCache, ?array $prevCache): void
    {
        $this->ath = [];
        $positionService = $this->positionService; /** @var ByBitLinearPositionService $positionService */
        $this->positions = $positionService->getAllPositions();
        $this->lastMarkPrices = $positionService->getLastMarkPrices();
        $this->initializeIms();

        $symbols = $this->getOpenedPositionsSymbols();
//        $totalUnrealizedPnl = $this->getTotalUnrealizedProfit();

        $singleCoin = null;
        foreach ($symbols as $symbol) {
            if ($singleCoin !== null && $symbol->associatedCoin() !== $singleCoin) {
                $singleCoin = null; break;
            }
            $singleCoin = $symbol->associatedCoin();
        }

        if ($singleCoin) {
            $balance = $this->exchangeAccountService->getContractWalletBalance($singleCoin);
            $spotBalance = $this->exchangeAccountService->getSpotWalletBalance($singleCoin);
            $total = $balance->totalWithUnrealized()->add($spotBalance->available);
        }

        $unrealisedTotal = 0;
        $rows = [];
        foreach ($symbols as $key => $symbol) {
            if ($symbolRows = $this->posInfo($symbol, $unrealisedTotal, $selectedCache ?? [], $prevCache ?? [])) {
                foreach ($symbolRows as $row) {
                    if ($row instanceof SeparatorRow) {
                        continue;
                    }
                    $row->addStyle(new RowStyle(fontColor: $key % 2 === 0 ? Color::BRIGHT_WHITE : Color::WHITE));
                }
                $rows = array_merge($rows, $symbolRows);
            }
        }

        if (isset($total)) {
            $this->cacheCollector['total'] = $total->value();
        }
//        $this->cacheCollector['unrealizedTotal'] = $unrealisedTotal;

        ### bottom START ###
        $bottomCells = [Cell::default($this->clock->now()->format('D d M H:i'))->setAlign(CellAlign::CENTER)];
        $balanceContent = isset($balance) && isset($spotBalance)
            ? sprintf('%s avail | %s free | %s real %s spot', self::formatPnl($balance->available)->value(), self::formatPnl($balance->free)->value(), self::formatPnl($balance->total)->value(), self::formatPnl($spotBalance->available)->value())
            : '';
        $bottomCells[] = sprintf('% 48s', $balanceContent);
        $bottomCells[] = isset($total) ? self::formatPnl($total) : '';
        $bottomCells[] = '';

        $pnlFormatter = $singleCoin ? static fn(float $pnl) => new CoinAmount($singleCoin, $pnl) : static fn($pnl) => (string)$pnl;
        $bottomCells[] = Cell::default($pnlFormatter($unrealisedTotal))->setAlign(CellAlign::RIGHT);
        $bottomCells[] = '';
        $pnlFormatter = $singleCoin ? static fn(float $pnl) => (new CoinAmount($singleCoin, $pnl))->value() : static fn($pnl) => (string)$pnl;
        if ($selectedCache !== null && isset($total)) {
            $totalCached = $selectedCache['total'];
//            $unrealisedPnlCached = $this->getTotalUnrealizedProfitFromCache($selectedCache, $symbols);
            $bottomCells[] = Cell::default(self::getFormattedDiff(a: $total->value(), b: $totalCached, formatter: $pnlFormatter))->setAlign(CellAlign::RIGHT);
        }
        if ($prevCache !== null && isset($total)) {
            $totalCached = $prevCache['total'];
//            $unrealisedPnlCached = $this->getTotalUnrealizedProfitFromCache($prevCache, $symbols);
            $bottomCells[] = Cell::default(self::getFormattedDiff(a: $total->value(), b: $totalCached, formatter: $pnlFormatter))->setAlign(CellAlign::RIGHT);
        }

        if ($this->bestWorstNote) {
            $bottomCells = array_merge($bottomCells, [Cell::colspan(6, $this->bestWorstNote)]);
        } else {
            $bottomCells = array_merge($bottomCells, ['', '', '', '']);
        }

        $rows[] = DataRow::default($bottomCells);
        ### bottom END ###

        $headerColumns = [
            $this->paramFetcher->getBoolOption(self::SHOW_FULL_TICKER_DATA_OPTION) ? 'symbol (last / mark / index)' : 'symbol',
            'entry / liq / size',
            'IM',
            'PNL%',
            'PNL',
        ];
        $headerColumns[] = 'smb';
        $selectedCache && $headerColumns[] = 'cache';
        $prevCache && $headerColumns[] = 'prev';
        $headerColumns = array_merge($headerColumns, [
            'smb',
            "init.liq.dist.\n /entry price",
            "curr.liq.dist.\n /entry price",
            "smb",
            "liq.\ndistance\npassed",
        ]);

        $headerColumns[] = "stops(\n between liq.\n and entry\n)\n[auto/manual]";

        ConsoleTableBuilder::withOutput($this->output)
            ->withHeader($headerColumns)
            ->withRows(...$rows)
            ->build()
            ->setStyle('box')
            ->render();
    }

    private function extraSymbolText(SymbolInterface|string $symbol, ?bool $isEquivalentHedge = false, ?Position $main = null): string
    {
        $symbol = $symbol instanceof SymbolInterface ? $symbol : $this->symbolProvider->getOrInitialize($symbol);

        if ($main === null) {
            $symbolPositions = $this->positions[$symbol->name()];
            $main = ($first = reset($symbolPositions))->getHedge()?->mainPosition ?? $first;
        }

        $extraSymbolText = $isEquivalentHedge === true ? strtolower($symbol->veryShortName()) : $symbol->veryShortName();
        if (in_array($symbol->name(), $this->symbolsToWatch, true)) {
            $extraSymbolCell = sprintf(
                '%s%s',
                CTH::colorizeText(substr($extraSymbolText, 0, 1), 'yellow-text'),
                CTH::colorizeText(substr($extraSymbolText, 1, strlen($extraSymbolText)), $isEquivalentHedge ? 'none' : ($main->isShort() ? 'bright-red-text' : 'green-text'))
            );
        } else {
            $extraSymbolCell = CTH::colorizeText($extraSymbolText, $isEquivalentHedge ? 'none' : ($main->isShort() ? 'bright-red-text' : 'green-text'));
        }

        return $extraSymbolCell;
    }

    /**
     * @return array<DataRow|SeparatorRow>
     */
    private function posInfo(SymbolInterface $symbol, float &$unrealizedTotal, array $specifiedCache = [], array $prevCache = []): array
    {
        $result = [];

        $positions = $this->positions[$symbol->name()];
        if (!$positions) {
            return [];
        }

        $markPrice = $this->lastMarkPrices[$symbol->name()];

        $hedge = $positions[array_key_first($positions)]->getHedge();
        $isEquivalentHedge = $hedge?->isEquivalentHedge();

        if ($isEquivalentHedge) {
            $main = $positions[Side::Sell->value];
            $support = $positions[Side::Buy->value];
        } else {
            $main = $hedge?->mainPosition ?? $positions[array_key_first($positions)];
            $support = $hedge?->supportPosition;
        }

        if ($support) {
            $supportPnl = $support->unrealizedPnl;
            $supportPositionCacheKey = self::positionCacheKey($support);

            if ($specifiedCache) {
                $supportPnlSpecifiedCacheValue = ($specifiedCache[$supportPositionCacheKey] ?? null)?->unrealizedPnl;
            }
            if ($prevCache) {
                $supportPnlPrevCacheValue = ($prevCache[$supportPositionCacheKey] ?? null)?->unrealizedPnl;
            }
        }

        $mainPositionCacheKey = self::positionCacheKey($main);
        $this->cacheCollector[$mainPositionCacheKey] = $main;

        if (($selectedCacheKey = $this->getSelectedCacheKey()) && !isset($specifiedCache[$mainPositionCacheKey])) {
            $this->addToCache($selectedCacheKey, $mainPositionCacheKey, $main);
        }

        $initialLiquidationDistance = $main->liquidationDistance();
        $distanceBetweenLiquidationAndTicker = $main->liquidationPrice()->deltaWith($markPrice);

        $priceToCalcPercentOfEntry = $main->side->isShort() ? $this->currentPriceDeltaFromLow($main->entryPrice()) : $main->entryPrice;


        $initialLiquidationDistancePercentOfEntry = Percent::fromPart($initialLiquidationDistance / $priceToCalcPercentOfEntry, false)->setOutputDecimalsPrecision(7)->setOutputFloatPrecision(1);
        $distanceBetweenLiquidationAndTickerPercentOfEntry = Percent::fromPart($distanceBetweenLiquidationAndTicker / $priceToCalcPercentOfEntry, false)->setOutputDecimalsPrecision(7)->setOutputFloatPrecision(1);
        if ($distanceBetweenLiquidationAndTickerPercentOfEntry->value() < $initialLiquidationDistancePercentOfEntry->value()) {
            $passedLiquidationDistancePercent = Percent::fromPart(($initialLiquidationDistancePercentOfEntry->value() - $distanceBetweenLiquidationAndTickerPercentOfEntry->value()) / $initialLiquidationDistancePercentOfEntry->value());
            $passedLiquidationDistancePercent = match (true) {
                $passedLiquidationDistancePercent->value() > 30 => CTH::colorizeText((string)$passedLiquidationDistancePercent, 'red-text'),
                $passedLiquidationDistancePercent->value() > 15 => CTH::colorizeText((string)$passedLiquidationDistancePercent, 'bright-red-text'),
                $passedLiquidationDistancePercent->value() > 5 => CTH::colorizeText((string)$passedLiquidationDistancePercent, 'yellow-text'),
                default => $passedLiquidationDistancePercent,
            };
        }

        $initialLiquidationDistancePercentOfEntry = match (true) {
            $initialLiquidationDistancePercentOfEntry->value() < 10 => CTH::colorizeText((string)$initialLiquidationDistancePercentOfEntry, 'bright-red-text'),
            $initialLiquidationDistancePercentOfEntry->value() < 30 => CTH::colorizeText((string)$initialLiquidationDistancePercentOfEntry, 'yellow-text'),
            default => $initialLiquidationDistancePercentOfEntry,
        };

        $distanceBetweenLiquidationAndTickerPercentOfEntry = match (true) {
            $distanceBetweenLiquidationAndTickerPercentOfEntry->value() < 10 => CTH::colorizeText((string)$distanceBetweenLiquidationAndTickerPercentOfEntry, 'bright-red-text'),
            $distanceBetweenLiquidationAndTickerPercentOfEntry->value() < 30 => CTH::colorizeText((string)$distanceBetweenLiquidationAndTickerPercentOfEntry, 'yellow-text'),
            default => $distanceBetweenLiquidationAndTickerPercentOfEntry,
        };

        $mainPositionPnl = $main->unrealizedPnl;

        if (!$main->isShortWithoutLiquidation()) {
            $stoppedVolume = $this->prepareStoppedPartContent($main, $markPrice);
        }

        $liquidationContent = $main->isShortWithoutLiquidation() ? '-' : sprintf('%9s', $main->liquidationPrice());
        $liquidationContent = !$main->isShortWithoutLiquidation() && $main->isLiquidationPlacedBeforeEntry()
            ? CTH::colorizeText($liquidationContent, 'yellow-text')
            : $liquidationContent;

        if ($this->paramFetcher->getBoolOption(self::SHOW_FULL_TICKER_DATA_OPTION)) {
            $ticker = $this->exchangeService->ticker($symbol);
            $this->cacheCollector[self::tickerCacheKey($ticker)] = $ticker;
            $cells = [sprintf('%8s: %8s   %8s   %8s', $symbol->shortName(), $ticker->lastPrice, $ticker->markPrice, $ticker->indexPrice)];
        } else {
            $funding = $this->marketService->getPreviousPeriodFundingRate($symbol);
            $fundingContent = sprintf('%.5f', $funding);
            if ($funding < 0) {
                $fundingContent = CTH::colorizeText($fundingContent, 'light-yellow-text');
            }
            $cells = [sprintf('%10s: % 8s / %8s %5s', $symbol->shortName(), $fundingContent, $markPrice, $this->athInfo($main, $markPrice))];
        }

        $cells = array_merge($cells, [
            sprintf(
                '%s: %9s %5s  %7s %3s   %6s',
                CTH::colorizeText(sprintf('%5s', $main->side->title()), $main->isShort() ? 'red-text' : 'green-text'),
                $main->entryPrice(),
                $this->athInfo($main, $main->entryPrice()),
                $liquidationContent,
//                '(100%)',
                $this->athInfo($main, $main->liquidationPrice(), 4),
                self::formatChangedValue(value: $main->size, specifiedCacheValue: (($specifiedCache[$mainPositionCacheKey] ?? null)?->size), formatter: static fn($value) => $symbol->roundVolume($value)),
            ),
            sprintf('%.1f', $this->ims[$main->symbol->name()]),
        ]);

        # PNL%
        $cells[] = Cell::default((new Percent($markPrice->getPnlPercentFor($main), false))->setOutputFloatPrecision(1)->setOutputDecimalsPrecision(7))->setAlign(CellAlign::RIGHT);
        # PNL value
        $mainPositionPnlContent = (string) self::formatPnl(new CoinAmount($symbol->associatedCoin(), $mainPositionPnl));
        $mainPositionPnlContent = $mainPositionPnl < 0 ? CTH::colorizeText($mainPositionPnlContent, 'red-text') : $mainPositionPnlContent;
        if (!$support) {
            $mainPositionPnlContent = '         ' . $mainPositionPnlContent;
        } else {
            # result PNL
            $resultPnl = $mainPositionPnl + $support->unrealizedPnl;
            $resultPnlContent = (string)self::formatPnl(new CoinAmount($symbol->associatedCoin(), $resultPnl));
            if ($mainPositionPnl < 0 && $resultPnl > 0) {
                $color = 'light-yellow-text';
            } else {
                $color = $resultPnl < 0 ? 'red-text' : 'bright-white-text';
            }
            $resultPnlContent = CTH::colorizeText($resultPnlContent, $color);
            $mainPositionPnlContent .= ' /' . $resultPnlContent;
        }
        $cells[] = $mainPositionPnlContent;
        $cells[] = $extraSymbolCell = $this->extraSymbolText($symbol, $isEquivalentHedge, $main);;

        if ($specifiedCache) {
            if (($cachedValue = ($specifiedCache[$mainPositionCacheKey] ?? null)?->unrealizedPnl) !== null) {
                if ($support && !$isEquivalentHedge && isset($supportPnlSpecifiedCacheValue)) {
                    $supportPnlDiffWithSpecifiedCache = $supportPnl - $supportPnlSpecifiedCacheValue;
                }
                $cells[] = self::formatPnlDiffCell($symbol, !$support, $mainPositionPnl, $cachedValue, withoutColor: $isEquivalentHedge, oppositePositionPnlDiffWithCache: $supportPnlDiffWithSpecifiedCache ?? null);
            } else {
                $cells[] = '';
            }
        }

        if ($prevCache) {
            if (($cachedValue = ($prevCache[$mainPositionCacheKey] ?? null)?->unrealizedPnl) !== null) {
                if ($support && !$isEquivalentHedge && isset($supportPnlPrevCacheValue)) {
                    $supportPnlDiffWithPrevCache = $supportPnl - $supportPnlPrevCacheValue;
                }
                $cells[] = self::formatPnlDiffCell($symbol, !$support, $mainPositionPnl, $cachedValue, withoutColor: $isEquivalentHedge, oppositePositionPnlDiffWithCache: $supportPnlDiffWithPrevCache ?? null);
            } else {
                $cells[] = '';
            }
        }

        $cells = array_merge($cells, [
            $extraSymbolCell,
            sprintf('%s %.2f', $initialLiquidationDistancePercentOfEntry, $initialLiquidationDistance),
            sprintf('%s %.2f', $distanceBetweenLiquidationAndTickerPercentOfEntry, $distanceBetweenLiquidationAndTicker),
            OutputHelper::linkToSymbolDashboard($symbol, $symbol->shortName()),
            !$isEquivalentHedge ? ($passedLiquidationDistancePercent ?? '') : '',
        ]);

        $cells[] = $stoppedVolume ?? '';

        $result[$main->side->value] = DataRow::default($cells);

        $unrealizedTotal += $mainPositionPnl;

        if ($support) {
            $supportPnl = $support->unrealizedPnl;
            $stoppedVolume = $this->prepareStoppedPartContent($support, $markPrice);

            $cells = [
                '',
                sprintf(
                    ' sup.: %9s        %s          %6s',
//                    ' sup.: %9s                  %6s',
                    $support->entryPrice(),
//                    self::athInfo($support, $support->entryPrice()),
                    CTH::colorizeText(sprintf('%7s', $support->getHedge()->getSupportRate()->setOutputFloatPrecision(1)), 'light-yellow-text'),
                    self::formatChangedValue(value: $support->size, specifiedCacheValue: (($specifiedCache[$supportPositionCacheKey] ?? null)?->size), formatter: static fn($value) => $symbol->roundVolume($value)),
                ),
                '',
            ];

            $cells[] = Cell::default((new Percent($markPrice->getPnlPercentFor($support), false))->setOutputFloatPrecision(1))->setAlign(CellAlign::RIGHT);
            $supportPnlContent = (string) self::formatPnl(new CoinAmount($symbol->associatedCoin(), $supportPnl));
            $supportPnlContent = !$isEquivalentHedge && $supportPnl < 0 ? CTH::colorizeText($supportPnlContent, 'yellow-text') : $supportPnlContent;

            $cells[] = new Cell($supportPnlContent, new CellStyle(fontColor: Color::WHITE));
            $cells[] = '';

            if ($specifiedCache) {
                if (isset($supportPnlSpecifiedCacheValue)) {
                    $cells[] = self::formatPnlDiffCell($symbol, false, $supportPnl, $supportPnlSpecifiedCacheValue, fontColor: Color::WHITE);
                } else {
                    $cells[] = '';
                }
            }
            if ($prevCache) {
                if (isset($supportPnlPrevCacheValue)) {
                    $cells[] = self::formatPnlDiffCell($symbol, false, $supportPnl, $supportPnlPrevCacheValue, fontColor: Color::WHITE);
                } else {
                    $cells[] = '';
                }
            }

            $cells = array_merge($cells, ['', '', '', '',  '']);
            $cells[] = $stoppedVolume ?? '';

            $result[$support->side->value] = DataRow::default($cells);

            $unrealizedTotal += $supportPnl;
            $this->cacheCollector[$supportPositionCacheKey] = $support;

            if (($selectedCacheKey = $this->getSelectedCacheKey()) && !isset($specifiedCache[$supportPositionCacheKey])) {
                $this->addToCache($selectedCacheKey, $supportPositionCacheKey, $support);
            }
        }

        if (count($result) > 1) {
            $result = [
                $result[Side::Sell->value],
                $result[Side::Buy->value],
            ];
        }

        $result = array_values($result);

        $result[] = new SeparatorRow();

        return $result;
    }

    private array $ath;

    private function getAthInfo(SymbolInterface $symbol): HighLowPrices
    {
        return $this->ath[$symbol->name()] ?? $this->ath[$symbol->name()] = TA::allTimeHighLow($symbol);
    }

    private function currentPriceDeltaFromLow(SymbolPrice $price): float
    {
        return $price->deltaWith($this->getAthInfo($price->symbol)->low);
    }

    public function currentPricePartOfAth(SymbolInterface $symbol, SymbolPrice $price): Percent
    {
        $allTimeHighLow = $this->getAthInfo($symbol);
        $currentPriceDeltaFromLow = $this->currentPriceDeltaFromLow($price);

        return Percent::fromPart($currentPriceDeltaFromLow / $allTimeHighLow->delta(), false);
    }

    private function athInfo(Position $position, SymbolPrice $price, int $decimals = 3): string
    {
        $percent = $this->currentPricePartOfAth($position->symbol, $price)->setOutputFloatPrecision(0)->setOutputDecimalsPrecision($decimals);
        $percentValue = $percent->value();

        if ($position->isShort()) {
            $text = match (true) {
                $percentValue <= 30 => CTH::colorizeText($percent, 'red-text'),
                $percentValue <= 50 => CTH::colorizeText($percent, 'light-yellow-text'),
                $percentValue <= 75 => CTH::colorizeText($percent, 'yellow-text'),
                default => CTH::colorizeText($percent, 'green-text'),
            };
        } else {
            $text = match (true) {
                $percentValue >= 75 => CTH::colorizeText($percent, 'red-text'),
                $percentValue >= 50 => CTH::colorizeText($percent, 'light-yellow-text'),
                $percentValue >= 20 => CTH::colorizeText($percent, 'yellow-text'),
                default => CTH::colorizeText($percent, 'green-text'),
            };
        }

        return $text;
    }

    private static function formatPnlDiffCell(
        SymbolInterface $symbol,
        bool $isMainWithoutSupport,
        float $a,
        float $b,
        ?bool $withoutColor = null,
        ?Color $fontColor = null,
        ?float $oppositePositionPnlDiffWithCache = null
    ): Cell {
        if ($fontColor) {
            $withoutColor = true;
        }

        $reference = self::formatPnl(new CoinAmount($symbol->associatedCoin(), 123))->setSigned(true);
        $pnlFormatter = static fn(float $pnl) => (string) self::formatPnl((new CoinAmount($symbol->associatedCoin(), $pnl)))->setSigned(true);

        $pnlDiffContent = self::getFormattedDiff(a: $a, b: $b, withoutColor: $withoutColor, formatter: $pnlFormatter, alreadySigned: true);
        if ($isMainWithoutSupport) {
            $pnlDiffContent = str_repeat(' ', $reference->getWholeLength()) . $pnlDiffContent;
        }

        if (isset($oppositePositionPnlDiffWithCache)) {
            $currentPositionPnlDiffWithPrevCache = $a - $b;
            $resultPnl = $oppositePositionPnlDiffWithCache + $currentPositionPnlDiffWithPrevCache;
            $pnlDiffContent .= ' / ' . self::getFormattedDiff(a: $resultPnl, b: 0, formatter: $pnlFormatter, alreadySigned: true);
        }

        $cell = Cell::default(trim($pnlDiffContent) !== '/' ? $pnlDiffContent : '');
        if ($fontColor) {
            $cell->addStyle(new CellStyle(fontColor: $fontColor));
        }

        return $cell;
    }

    private static function formatPnl(CoinAmount $amount): CoinAmount
    {
        return $amount->setFloatPrecision(2);
    }

    private function getOpenedPositionsSymbols(): array
    {
        $symbolsRaw = [];
        $equivalentHedgedSymbols = [];
        foreach ($this->positions as $symbolRaw => $symbolPositions) {
            $symbolsRaw[] = $symbolRaw;
            if ($this->moveHedgedSymbolsUp && $symbolPositions[array_key_first($symbolPositions)]?->getHedge()?->isEquivalentHedge()) {
                $equivalentHedgedSymbols[] = $symbolRaw;
            }
        }

        if ($this->useSavedSort && !$this->useIMForSort) {
            if ($this->savedRawSymbolsSort === null) {
                OutputHelper::print('Saved sort not found');
            } else {
                $newPositionsSymbols = array_diff($symbolsRaw, $this->savedRawSymbolsSort);
                $symbolsRawSorted = array_intersect($this->savedRawSymbolsSort, $symbolsRaw);
                $symbolsRawSorted = array_merge($symbolsRawSorted, $newPositionsSymbols);
                $symbolsRaw = $symbolsRawSorted;
            }
        }

        if ($this->rawSymbolsSetToMoveUp && ($providedItems = array_intersect($this->rawSymbolsSetToMoveUp, $symbolsRaw))) {
            $symbolsRaw = array_merge($providedItems, array_diff($symbolsRaw, $providedItems));
        }

        if ($this->rawSymbolsSetToMoveDown && ($providedItems = array_intersect($this->rawSymbolsSetToMoveDown, $symbolsRaw))) {
            $symbolsRaw = array_merge(array_diff($symbolsRaw, $providedItems), $providedItems);
        }

        if (!$this->currentSortSaved && $this->paramFetcher->getBoolOption(self::SAVE_SORT_OPTION)) {
            $item = $this->cache->getItem(self::SortCacheKey)->set($symbolsRaw)->expiresAfter(null);
            $this->cache->save($item);
            $this->currentSortSaved = true;
        }

        if (!$this->currentStateGonnaBeSaved) {
            if ($this->showSymbolsRawValues) {
                $symbolsRaw = array_intersect($this->showSymbolsRawValues, $symbolsRaw);
            } elseif (
                $this->hideSymbolsRawValues
                && $hideSymbolsRawValues = array_intersect($this->hideSymbolsRawValues, $symbolsRaw)
            ) {
                $symbolsRaw = array_diff($symbolsRaw, $hideSymbolsRawValues);
            }
        }

        $sortBy = $this->sortBy;
        $maxChangeMode = 'as-is'; // $maxChangeMode = 'highlight';

        $symbolsToWatch = $this->symbolsToWatch;
        if (
            ($byMaxNegativeChange = in_array('max-negative-change', $sortBy, true))
            || ($byMaxPositiveChange = in_array('max-positive-change', $sortBy, true))
        ) {
            [
                'withSpecifiedCache' => $maxChangeMap,
                'withPrevIteration' => $maxChangeMapWithPrevCache,
                'totalUnrealized' => $totalUnrealized,
            ] = $this->getSymbolsMaxChangeMap($byMaxNegativeChange);

            if ($maxChangeMap) {
                asort($maxChangeMap);
                $maxChangeMap = array_keys($maxChangeMap);

                if ($maxChangeMapWithPrevCache) {
                    asort($maxChangeMapWithPrevCache);

                    $lastSymbol = array_key_last($maxChangeMapWithPrevCache);

                    if ($maxChangeMode === 'highlight' && $lastSymbol) {
                        $maxChangeMap = array_diff($maxChangeMap, [$lastSymbol]);
                        $maxChangeMap[] = $lastSymbol;
                    }

                    if ($byMaxPositiveChange ?? null) {
                        $firstSymbol = array_key_first($maxChangeMapWithPrevCache);
                    } else {
                        // finding best by true initial margin
                        $symbolsInitialMarginMap = $this->getSymbolsInitialMarginMap();
                        $maxChangeMapWithPrevCacheByTrueIm = [];
                        foreach ($maxChangeMapWithPrevCache as $symbolRaw => $item) {
                            $maxChangeMapWithPrevCacheByTrueIm[$symbolRaw] = $item / $symbolsInitialMarginMap[$symbolRaw];
                        }
                        asort($maxChangeMapWithPrevCacheByTrueIm);

                        $firstSymbol = array_key_first($maxChangeMapWithPrevCacheByTrueIm);
                    }
                    $bestWorstNotes = [];

                    if ($firstSymbol) {
                        $sign = $byMaxNegativeChange ? -1 : 1;
                        $bestWorstNotes[] = $this->bestWorstSymbolInfo($firstSymbol, $byMaxNegativeChange, $maxChangeMapWithPrevCache, $totalUnrealized, $maxChangeMap, $sign);
                    }

                    if ($maxChangeMode === 'as-is' && $lastSymbol) {
                        $sign = $byMaxNegativeChange ? 1 : -1;
                        $bestWorstNotes[] = $this->bestWorstSymbolInfo($lastSymbol, !$byMaxNegativeChange, $maxChangeMapWithPrevCache, $totalUnrealized, $maxChangeMap, $sign);
                    }

                    if ($bestWorstNotes) {
                        $this->bestWorstNote = implode(' | ', $bestWorstNotes);
                    }
                }

                $maxChangeMap = array_values($maxChangeMap);
                $symbolsRaw = array_intersect($maxChangeMap, $symbolsRaw);
            }
        } elseif (
            in_array('watch', $sortBy, true)
            && in_array('im', $sortBy, true)
        ) {
            $symbolsInitialMarginMap = $this->getSymbolsInitialMarginMap();
            asort($symbolsInitialMarginMap);
            $symbolsInitialMarginMap = array_values(array_keys($symbolsInitialMarginMap));

            $restSymbols = array_diff($symbolsRaw, $symbolsToWatch);
            $restSymbols = array_intersect($symbolsInitialMarginMap, $restSymbols);

            $watchSortedByIM = array_intersect($symbolsInitialMarginMap, $symbolsToWatch);
            $symbolsRaw = array_merge($restSymbols, $watchSortedByIM);
        } elseif (in_array('watch', $sortBy, true)) {
            $symbolsRaw = array_merge(array_diff($symbolsRaw, $symbolsToWatch), $symbolsToWatch);
        } elseif (in_array('im', $sortBy, true) || $this->useIMForSort) {
            $symbolsInitialMarginMap = $this->getSymbolsInitialMarginMap();
            asort($symbolsInitialMarginMap);
            $symbolsInitialMarginMap = array_values(array_keys($symbolsInitialMarginMap));
            $symbolsRaw = array_intersect($symbolsInitialMarginMap, $symbolsRaw);
        }

        if ($this->moveHedgedSymbolsUp) {
            $symbolsRaw = array_merge($equivalentHedgedSymbols, array_diff($symbolsRaw, $equivalentHedgedSymbols));
        }

        return $this->rawSymbolsToValueObjects(...$symbolsRaw);
    }

    private function bestWorstSymbolInfo(string $symbolRaw, bool $byMaxNegativeChange, array $maxChangeMapWithPrevCache, array $totalUnrealized, array $maxChangeMap, int $sign)
    {
        $pnl = $sign * $maxChangeMapWithPrevCache[$symbolRaw];

        $symbol = $this->symbolProvider->getOrInitialize($symbolRaw);
        $pnlFormatter = static fn(float $pnl) => (string) self::formatPnl((new CoinAmount($symbol->associatedCoin(), $pnl)))->setSigned(true);

        if ($byMaxNegativeChange) {
            $b = 0; $a = abs($pnl);
        } else {
            $a = 0; $b = abs($pnl);
        }

        $totalSymbolPnl = $totalUnrealized[$symbol->name()];
        $pnlContent = (string) self::formatPnl(new CoinAmount($symbol->associatedCoin(), $totalSymbolPnl));
        $pnlContent = $totalSymbolPnl < 0 ? CTH::colorizeText($pnlContent, 'red-text') : $pnlContent;

        $index = array_search($symbolRaw, $maxChangeMap);

        return sprintf(
            '[%d] %s:   %s (%s)',
            $index,
            $this->extraSymbolText($symbolRaw),
            self::getFormattedDiff(a: $a, b: $b, formatter: $pnlFormatter, alreadySigned: $byMaxNegativeChange),
            $pnlContent
        );
    }

    /**
     * @return SymbolInterface[]
     */
    private function rawSymbolsToValueObjects(string ...$symbolsRaw): array
    {
        return array_map(fn (string $symbolRaw) => $this->symbolProvider->getOrInitialize($symbolRaw), $symbolsRaw);
    }

    public function getTotalUnrealizedProfit(): float
    {
        $result = 0;
        foreach ($this->positions as $symbolRaw => $positions) {
            foreach ($positions as $position) {
                $result += $position->unrealizedPnl;
            }
        }

        return $result;
    }

    /**
     * @param bool $negative
     * @return array{withSpecifiedCache: ?array, withPrevIteration: ?array, totalUnrealized: ?array}
     */
    public function getSymbolsMaxChangeMap(bool $negative = false): array
    {
        if (!$specifiedCache = $this->selectedCacheToShowDiffWith) {
            return ['withSpecifiedCache' => null, 'withPrevIteration' => null, 'totalUnrealized' => null];
        }

        $prevIteration = $this->previousIterationCache;

        $totalUnrealized = [];
        $withSpecifiedCacheResult = [];
        $withPrevCacheResult = [];
        foreach ($this->positions as $symbolRaw => $positions) {
            $hedge = $positions[array_key_first($positions)]->getHedge();
            $isEquivalentHedge = $hedge?->isEquivalentHedge();

            $support = null;
            if ($isEquivalentHedge) {
                $main = $positions[Side::Sell->value];
                $support = $positions[Side::Buy->value];
            } else {
                $main = $hedge?->mainPosition ?? $positions[array_key_first($positions)];
                $support = $hedge?->supportPosition;
            }

            $mainPositionPnl = $main->unrealizedPnl;

            $supportPnl = null;
            if ($support) {
                $supportPnl = $support->unrealizedPnl;
                $supportPositionCacheKey = self::positionCacheKey($support);

                $supportPnlSpecifiedCacheValue = ($specifiedCache[$supportPositionCacheKey] ?? null)?->unrealizedPnl;
                if ($prevIteration) {
                    $supportPnlPrevCacheValue = ($prevIteration[$supportPositionCacheKey] ?? null)?->unrealizedPnl;
                }
            }

            $totalUnrealized[$symbolRaw] = $mainPositionPnl + ($supportPnl??null) ?? 0;

            $mainPositionCacheKey = self::positionCacheKey($main);

            $withSpecifiedCacheResult[$symbolRaw] = 0;
            $withPrevCacheResult[$symbolRaw] = 0;

            if (($cachedValue = ($specifiedCache[$mainPositionCacheKey] ?? null)?->unrealizedPnl) !== null) {
                $currentPositionPnlDiffWithPrevCache = $mainPositionPnl - $cachedValue;

                if ($support && !$isEquivalentHedge && isset($supportPnlSpecifiedCacheValue)) {
                    $supportPnlDiffWithSpecifiedCache = $supportPnl - $supportPnlSpecifiedCacheValue;
                    $resultPnlDiff = $supportPnlDiffWithSpecifiedCache + $currentPositionPnlDiffWithPrevCache;
                } else {
                    $resultPnlDiff = $currentPositionPnlDiffWithPrevCache;
                }

                $withSpecifiedCacheResult[$symbolRaw] = $resultPnlDiff;
            }

            if ($prevIteration) {
                if (($cachedValue = ($prevIteration[$mainPositionCacheKey] ?? null)?->unrealizedPnl) !== null) {
                    $currentPositionPnlDiffWithPrevCache = $mainPositionPnl - $cachedValue;

                    if ($support && !$isEquivalentHedge && isset($supportPnlPrevCacheValue)) {
                        $supportPnlDiffWithSpecifiedCache = $supportPnl - $supportPnlPrevCacheValue;
                        $resultPnlDiff = $supportPnlDiffWithSpecifiedCache + $currentPositionPnlDiffWithPrevCache;
                    } else {
                        $resultPnlDiff = $currentPositionPnlDiffWithPrevCache;
                    }

                    $withPrevCacheResult[$symbolRaw] = $resultPnlDiff;
                }
            }
        }

        $sign = $negative ? -1 : 1;
        $withSpecifiedCache = array_map(static fn(float $totalPnlDiff) => (string)($sign * $totalPnlDiff), $withSpecifiedCacheResult);
        $withPrevCache = array_map(static fn(float $totalPnlDiff) => (string)($sign * $totalPnlDiff), $withPrevCacheResult);

        return [
            'withSpecifiedCache' => $withSpecifiedCache,
            'totalUnrealized' => $totalUnrealized,
            'withPrevIteration' => $prevIteration ? $withPrevCache : null,
        ];
    }

    public function getSymbolsInitialMarginMap(): array
    {
        return array_map(static fn(float $im) => (string)$im, $this->ims);
    }

    public function initializeIms(): void
    {
        foreach ($this->positions as $symbolRaw => $positions) {
            $symbolIm = 0;
            foreach ($positions as $position) {
                $symbolIm += InitialMarginHelper::realInitialMargin($position);
            }
            $this->ims[$symbolRaw] = $symbolIm;
        }
    }

    /**
     * @param SymbolInterface[] $symbols
     */
    private function getTotalUnrealizedProfitFromCache(array $cache, array $symbols): float
    {
        $result = 0;
        foreach ($symbols as $symbol) {
            foreach ([Side::Buy, Side::Sell] as $side) {
                /** @var Position $positionCache */
                $positionCacheKey = self::positionCacheKeyByRaw($symbol, $side);
                if ($positionCache = ($cache[$positionCacheKey] ?? null)) {
                    $result += $positionCache->unrealizedPnl;
                }
            }
        }

        return $result;
    }

    private ?string $selectedCacheKey = null;

    /**
     * @todo DRY
     */
    private function getSelectedCacheKey(): ?string
    {
        if ($this->selectedCacheKey !== null) {
            return $this->selectedCacheKey;
        }

        if ($this->showDiffWithOption) {
            $selectedDataKey = $this->showDiffWithOption;
            if ($selectedDataKey === 'last') {
                assert($savedKeys = $this->getManuallySavedDataCacheKeys(), new Exception('Trying to get last manually saved cache: saved cache not found'));
                $selectedDataKey = $savedKeys[array_key_last($savedKeys)];
            }

            return $this->selectedCacheKey = $selectedDataKey;
        }

        return null;
    }

    private function getCacheRecordToShowDiffWith(): ?array
    {
        $cache = null;
        if ($this->showDiffWithOption) {
            $selectedDataKey = $this->showDiffWithOption;
            if ($selectedDataKey === 'last') {
                assert($savedKeys = $this->getManuallySavedDataCacheKeys(), new Exception('Trying to get last manually saved cache: saved cache not found'));
                $selectedDataKey = $savedKeys[array_key_last($savedKeys)];
            }

            assert(($cacheItem = $this->cache->getItem($selectedDataKey))->isHit(), new Exception(sprintf('Cannot find cache for "%s"', $selectedDataKey)));

            if ($this->paramFetcher->getBoolOption(self::REMOVE_PREVIOUS_CACHE_OPTION)) {
                $doRemove = $this->showDiffWithOption !== 'last' || $this->io->confirm('"last" selected as cache. Are you sure that you want to remove all cache saved before it?', false);
                if ($doRemove) {
                    $this->removeSavedDataCacheBefore($selectedDataKey);
                }
            }

            $cache = $cacheItem->get();
        }

        return $cache;
    }

    private static function formatChangedValue(int|float $value, int|float|null $specifiedCacheValue = null, int|float|null $prevIterationValue = null, ?callable $formatter = null, ?bool $withoutColor = null): string
    {
        $formatter = $formatter ?? static fn ($val) => (string)$val;
        $result = $formatter($value);

        $items = [];

        if ($specifiedCacheValue !== null && $value !== $specifiedCacheValue) {
            $items[] = self::getFormattedDiff($value, $specifiedCacheValue, $withoutColor, $formatter) ?? 0;
        }

        if (
            $prevIterationValue
            && ($diff = self::getFormattedDiff($value, $prevIterationValue, $withoutColor, $formatter)) !== null
        ) {
            $items[] = sprintf('%s', $diff);
        }

        if ($items) {
            $result .= sprintf(' (%s)', implode(' | ', $items));
        }

        return $result;
    }

    private static function getFormattedDiff(int|float $a, int|float $b, ?bool $withoutColor = null, ?callable $formatter = null, bool $alreadySigned = false): string
    {
        $diff = $a - $b;

        if ($diff === 0.00 || $diff === 0) {
            return '';
        }

        [$sign, $color] = match (true) {
            $diff > 0 => ['+', 'green-text'],
            $diff < 0 => ['', 'bright-red-text'],
            default => [null, null]
        };

        if ($withoutColor === true) {
            $color = null;
        }

        if ($alreadySigned) {
            $value = $formatter($diff);
        } else {
            $value = $formatter(abs($diff));
            $value = $diff < 0 ? -$value : $value;
            $value = sprintf('%s%s', $sign ?? '', $value);
        }


        return $color ? CTH::colorizeText($value, $color) : $value;
    }

    private static function positionCacheKey(Position $position): string {return sprintf('position_%s_%s', $position->symbol->name(), $position->side->value);}
    private static function positionCacheKeyByRaw(SymbolInterface $symbol, Side $side): string {return sprintf('position_%s_%s', $symbol->name(), $side->value);}

    private static function tickerCacheKey(Ticker $ticker): string {return sprintf('ticker_%s', $ticker->symbol->name());}

    private function getSavedDataCacheKeys(): array
    {
        $cacheItem = $this->cache->getItem(self::SavedDataKeysCacheKey);

        return $cacheItem->isHit() ? $cacheItem->get() : [];
    }

    private function addSavedDataCacheKey(string $cacheKey): void
    {
        $savedDataKeys = $this->getSavedDataCacheKeys();
        $savedDataKeys[] = $cacheKey;

        $this->cache->save($this->cache->getItem(self::SavedDataKeysCacheKey)->set($savedDataKeys)->expiresAfter(null));
    }

    private function getManuallySavedDataCacheKeys(): array
    {
        $cacheItem = $this->cache->getItem(self::Manually_SavedDataKeysCacheKey);

        return $cacheItem->isHit() ? $cacheItem->get() : [];
    }

    private function addManuallySavedDataCacheKey(string $cacheKey): void
    {
        $savedDataKeys = $this->getManuallySavedDataCacheKeys();
        $savedDataKeys[] = $cacheKey;

        $this->cache->save($this->cache->getItem(self::Manually_SavedDataKeysCacheKey)->set($savedDataKeys)->expiresAfter(null));
    }
    private function removeSavedDataCacheBefore(string $cacheKey): void
    {
        $savedDataKeys = $this->getSavedDataCacheKeys();

        $removeFromKey = null;
        foreach ($savedDataKeys as $key => $savedCacheKey) {
            if ($savedCacheKey === $cacheKey) {
                break;
            }

            $this->cache->delete($savedCacheKey);
            $removeFromKey = $key;
        }

        if ($removeFromKey !== null) {
            $savedDataKeys = array_slice($savedDataKeys, $removeFromKey + 1);
        }

        $savedDataKeys[] = $cacheKey;

        $this->cache->save($this->cache->getItem(self::SavedDataKeysCacheKey)->set($savedDataKeys)->expiresAfter(null));
    }

    private function prepareStoppedPartContent(Position $position, $markPrice): ?string
    {
        $symbol = $position->symbol;
        $positionSide = $position->side;
        $this->entityManager->clear();

        $stops = $this->stopRepository->findActive(
            symbol: $symbol,
            side: $positionSide,
            qbModifier: static fn(QB $qb) => QueryHelper::addOrder($qb, 'price', $positionSide->isShort() ? 'ASC' : 'DESC'),
        );

        // @todo | AllOpenedPositionsInfoCommand| какое значение использовать для $mainPosStopsApplicableRange
        $mainPosStopsApplicableRange = null;
        if ($position->isMainPosition() || $position->isPositionWithoutHedge()) {
            $liquidationParameters = $this->liquidationDynamicParametersFactory->fakeWithoutHandledMessage($position, new Ticker($position->symbol, $markPrice, $markPrice, $markPrice));
            // @todo some `actualStopsRangeBoundNearestToPositionLiquidation`
            $criticalRange = $liquidationParameters->criticalRange();
            $boundBeforeLiquidation = $position->isShort() ? $criticalRange->to() : $criticalRange->from();
            $tickerBound = $position->isShort() ? 0 : 9999999; // all stops from the start =)
            $mainPosStopsApplicableRange = PriceRange::create($boundBeforeLiquidation, $tickerBound, $position->symbol);
        }

        /**
         * @var Stop[] $autoStops
         * @var Stop[] $manualStops
         * @var Stop[] $fixationStops
         * @var Stop[] $lockInProfitStops
         */
        $autoStops = array_filter($stops, static fn(Stop $stop) => $stop->isAdditionalStopFromLiquidationHandler() && !self::isMainPositionStopNotLyingInsideApplicableRange($stop, $position, $mainPosStopsApplicableRange));
        $manualStops = array_filter($stops, static fn(Stop $stop) => $stop->isManuallyCreatedStop() && !self::isMainPositionStopNotLyingInsideApplicableRange($stop, $position, $mainPosStopsApplicableRange));
        $fixationStops = array_filter($stops, static fn(Stop $stop) => $stop->isAnyKindOfFixation() && !self::isMainPositionStopNotLyingInsideApplicableRange($stop, $position, $mainPosStopsApplicableRange));
        $lockInProfitStops = array_filter($stops, static fn(Stop $stop) => $stop->createdAsLockInProfit() && !self::isMainPositionStopNotLyingInsideApplicableRange($stop, $position, $mainPosStopsApplicableRange));

        $stoppedVolume = [];
        if ($manualStops) {
            $stoppedVolume[] = self::getStopsCollectionInfo($manualStops, $position, $markPrice,
                $this->showStops
                    ? static fn($stoppedPartPct, $stopsCount, $firstStopDistancePnlPct, $firstStopDistanceColor) =>
                        sprintf('%s|%d[m.%s]', $stoppedPartPct, $stopsCount, CTH::colorizeText($firstStopDistancePnlPct, $firstStopDistanceColor))
                    : static fn($stoppedPartPct, $stopsCount, $firstStopDistancePnlPct, $firstStopDistanceColor) =>
                        sprintf('%s|%d[m]', $stoppedPartPct, $stopsCount)
            );
        }
        if ($fixationStops) {
            $stopsColor = $position->isMainPositionOrWithoutHedge() ? ($position->isShort() ? 'bright-red-text' : 'green-text') : 'yellow-text';
            $stoppedVolume[] = self::getStopsCollectionInfo($fixationStops, $position, $markPrice,
                $this->showStops
                    ? static fn($stoppedPartPct, $stopsCount, $firstStopDistancePnlPct, $firstStopDistanceColor) =>
                        sprintf('%s|%d[%s.%s]', $stoppedPartPct, $stopsCount, CTH::colorizeText('f', $stopsColor), CTH::colorizeText($firstStopDistancePnlPct, $firstStopDistanceColor))
                    : static fn($stoppedPartPct, $stopsCount, $firstStopDistancePnlPct, $firstStopDistanceColor) =>
                        sprintf('%s|%d[%s]', $stoppedPartPct, $stopsCount, CTH::colorizeText('f', $stopsColor))
            );
        }
        if ($lockInProfitStops) {
            $stoppedVolume[] = self::getStopsCollectionInfo($lockInProfitStops, $position, $markPrice,
                $this->showStops
                    ? static fn($stoppedPartPct, $stopsCount, $firstStopDistancePnlPct, $firstStopDistanceColor) =>
                        sprintf('%s|%d[%s.%s]', $stoppedPartPct, $stopsCount, CTH::colorizeText('l', 'green-text'), TH::colorizeText($firstStopDistancePnlPct, $firstStopDistanceColor))
                    : static fn($stoppedPartPct, $stopsCount, $firstStopDistancePnlPct, $firstStopDistanceColor) =>
                        sprintf('%s|%d[%s]', $stoppedPartPct, $stopsCount, CTH::colorizeText('l', 'green-text'))
            );
        }
        if ($autoStops) {
            $stoppedVolume[] = self::getStopsCollectionInfo($autoStops, $position, $markPrice,
                $this->showStops
                    ? static fn($stoppedPartPct, $stopsCount, $firstStopDistancePnlPct, $firstStopDistanceColor) =>
                        CTH::colorizeText(sprintf('%s|%d[a.%s]', $stoppedPartPct, $stopsCount, $firstStopDistancePnlPct), $firstStopDistanceColor)
                    : static fn($stoppedPartPct, $stopsCount, $firstStopDistancePnlPct, $firstStopDistanceColor) =>
                        CTH::colorizeText(sprintf('%s|%d[a]', $stoppedPartPct, $stopsCount), 'red-text')
            );
        }

        return $stoppedVolume ? implode('/', $stoppedVolume) : null;
    }

    private static function getStopsCollectionInfo(
        array $stops,
        Position $position,
        SymbolPrice $markPrice,
        callable $formatter,
    ): string {
        $symbol = $position->symbol;
        $entryPrice = $position->entryPrice();

        $stoppedPartPct = (new Percent((new StopsCollection(...$stops))->volumePart($position->size), false))->setOutputFloatPrecision(0);
        $firstStop = $stops[array_key_first($stops)];
        $firstStopPrice = $symbol->makePrice($firstStop->getPrice());
        $firstStopDistancePnlPct = PnlHelper::convertAbsDeltaToPnlPercentOnPrice(
            $entryPrice->differenceWith($firstStopPrice)->deltaForPositionLoss($position->side),
            $entryPrice
        );
        $firstStopDistancePnlPct->setOutputFloatPrecision(1);

        $firstStopDistancePnlPctWithTicker = PnlHelper::convertAbsDeltaToPnlPercentOnPrice(
            $markPrice->differenceWith($firstStopPrice)->absDelta(),
            $markPrice
        );
        // @todo | symbol | some way to get values based on symbol -> move to settings?
        $bounds = [
            SymbolEnum::BTCUSDT->value => 100,
            SymbolEnum::ETHUSDT->value => 200,
        ];
        $bound = $bounds[$symbol->name()] ?? 300;
        $firstStopDistanceColor = 'none';
        if (
            $firstStopDistancePnlPctWithTicker->value() < $bound
            || ($position->isShort() && $firstStopPrice->lessOrEquals($markPrice))
            || ($position->isLong() && $firstStopPrice->greaterOrEquals($markPrice))
        ) {
            $firstStopDistanceColor = 'red-text';
        }

        return $formatter($stoppedPartPct, count($stops), $firstStopDistancePnlPct, $firstStopDistanceColor);
    }

    private static function isMainPositionStopNotLyingInsideApplicableRange(Stop $stop, Position $position, ?PriceRange $mainPosStopsApplicableRange = null): bool
    {
        if (!$position->isPositionWithoutHedge() && !$position->isMainPosition()) {
            return false;
        }

        return !$stop->getSymbol()->makePrice($stop->getPrice())->isPriceInRange($mainPosStopsApplicableRange);
    }

    /**
     * @param ByBitExchangeAccountService $exchangeAccountService
     */
    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly CalcPositionLiquidationPriceHandler $calcPositionLiquidationPriceHandler,
        private readonly PositionsCache $positionsCache,
        PositionServiceInterface $positionService,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly StopRepository $stopRepository,
        private readonly AppSettingsProviderInterface $settings,
        private readonly OpenedPositionsCache $openedPositionsCache,
        private readonly LiquidationDynamicParametersFactoryInterface $liquidationDynamicParametersFactory,
        private readonly MarketServiceInterface $marketService,
        private readonly EntityManagerInterface $entityManager,
        ?string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
