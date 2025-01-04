<?php

namespace App\Command\Position;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Domain\Coin\CoinAmount;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\StopsCollection;
use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Infrastructure\Cache\PositionsCache;
use App\Output\Table\Dto\Cell;
use App\Output\Table\Dto\DataRow;
use App\Output\Table\Dto\SeparatorRow;
use App\Output\Table\Dto\Style\CellStyle;
use App\Output\Table\Dto\Style\Enum\Color;
use App\Output\Table\Formatter\ConsoleTableBuilder;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Cache\CacheInterface;

use function array_merge;
use function sprintf;

#[AsCommand(name: 'p:opened')]
class AllOpenedPositionsInfoCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;
    use PriceRangeAwareCommand;

    private const DEFAULT_UPDATE_INTERVAL = '15';
    private const DEFAULT_SAVE_CACHE_INTERVAL = '5';

    private const SortCacheKey = 'opened_positions_sort';
    private const SavedDataKeysCacheKey = 'saved_data_cache_keys';

    private const WITH_SAVED_SORT_OPTION = 'sorted';
    private const SAVE_SORT_OPTION = 'save-sort';
    private const DIFF_WITH_SAVED_CACHE_OPTION = 'diff';
    private const REMOVE_PREVIOUS_CACHE_OPTION = 'remove-prev';
    private const SHOW_CACHE_OPTION = 'show-cache';
    private const UPDATE_OPTION = 'update';
    private const UPDATE_INTERVAL_OPTION = 'update-interval';
    private const SAVE_EVERY_N_ITERATION_OPTION = 'save-cache-interval';

    private array $cacheCollector = [];
    private ?string $showDiffWithOption;

    /** @var Symbol[] */
    private array $symbols;

    /** @var Stop[] */
    private array $stops;

    protected function configure(): void
    {
        $this
            ->addOption(self::WITH_SAVED_SORT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Apply saved sort')
            ->addOption(self::SAVE_SORT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Save current sort')
            ->addOption(self::DIFF_WITH_SAVED_CACHE_OPTION, null, InputOption::VALUE_OPTIONAL, 'Output diff with saved cache')
            ->addOption(self::REMOVE_PREVIOUS_CACHE_OPTION, null, InputOption::VALUE_NEGATABLE, 'Remove previous cache')
            ->addOption(self::UPDATE_OPTION, null, InputOption::VALUE_NEGATABLE, 'Update?')
            ->addOption(self::UPDATE_INTERVAL_OPTION, null, InputOption::VALUE_REQUIRED, 'Update interval', self::DEFAULT_UPDATE_INTERVAL)
            ->addOption(self::SAVE_EVERY_N_ITERATION_OPTION, null, InputOption::VALUE_REQUIRED, 'Number of iterations to wait before save current state', self::DEFAULT_SAVE_CACHE_INTERVAL)
            ->addOption(self::SHOW_CACHE_OPTION, null, InputOption::VALUE_NEGATABLE, 'Show cache records?')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->showDiffWithOption = $this->paramFetcher->getStringOption(self::DIFF_WITH_SAVED_CACHE_OPTION, false);

        $symbols = $this->positionService->getOpenedPositionsSymbols();
        if ($this->paramFetcher->getBoolOption(self::WITH_SAVED_SORT_OPTION)) {
            $sort = ($item = $this->cache->getItem(self::SortCacheKey))->isHit() ? $item->get() : null;
            if ($sort === null) {
                OutputHelper::print('Saved sort not found');
            } else {
                $symbolsRaw = array_map(static fn (Symbol $symbol) => $symbol->value, $symbols);
                $newPositionsSymbols = array_diff($symbolsRaw, $sort);
                $symbolsRawSorted = array_intersect($sort, $symbolsRaw);
                $symbolsRawSorted = array_merge($symbolsRawSorted, $newPositionsSymbols);
                $symbols = array_map(static fn (string $symbolRaw) => Symbol::from($symbolRaw), $symbolsRawSorted);
            }
        }
        $this->symbols = $symbols;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->getFormatter()->setStyle('red-text', new OutputFormatterStyle(foreground: 'red', options: ['bold', 'blink']));
        $output->getFormatter()->setStyle('green-text', new OutputFormatterStyle(foreground: 'green', options: ['bold', 'blink']));

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
            $iteration++;
            $this->cacheCollector = [];

            $cache = $this->getCacheRecordToShowDiffWith($previousIterationCache);

            $prevCache = null;
            if ($this->showDiffWithOption !== 'last') {
                $prevCache = $previousIterationCache;
            }

            $this->doOut($cache, $prevCache);

            $saveCurrentState = !$updateEnabled || $iteration % $this->paramFetcher->getIntOption(self::SAVE_EVERY_N_ITERATION_OPTION) === 0;
            if ($saveCurrentState) {
                $cachedDataCacheKey = sprintf('opened_positions_data_cache_%s', $this->clock->now()->format('Y-m-d_H-i-s'));
                $item = $this->cache->getItem($cachedDataCacheKey)->set($this->cacheCollector)->expiresAfter(null);
                $this->cache->save($item);
                $this->addSavedDataCacheKey($cachedDataCacheKey);
                OutputHelper::print(sprintf('Cache saved as "%s"', $cachedDataCacheKey));
            }
            $previousIterationCache = $this->cacheCollector;

            $updateEnabled && sleep($this->paramFetcher->getIntOption(self::UPDATE_INTERVAL_OPTION));
        } while ($updateEnabled);

        return Command::SUCCESS;
    }

    public function doOut(?array $selectedCache, ?array $prevCache): void
    {
        $this->stops = $this->stopRepository->findActiveCreatedByLiquidationHandler();

        $unrealisedTotal = 0;
        $rows = [];
        foreach ($this->symbols as $symbol) {
            if ($symbolRows = $this->posInfo($symbol, $unrealisedTotal, $selectedCache ?? [], $prevCache ?? [])) {
                $rows = array_merge($rows, $symbolRows);
            }
        }

        $this->cacheCollector['unrealizedTotal'] = $unrealisedTotal;
        ### bottom START ###
        $coin = null;
        foreach ($this->symbols as $symbol) {
            if ($coin !== null && $symbol->associatedCoin() !== $coin) {
                $coin = null; break;
            }
            $coin = $symbol->associatedCoin();
        }
        $pnlFormatter = $coin ? static fn(float $pnl) => new CoinAmount($coin, $pnl) : static fn($pnl) => (string)$pnl;
        $bottomCells = [
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            $pnlFormatter($unrealisedTotal),
        ];

        $pnlFormatter = $coin ? static fn(float $pnl) => (new CoinAmount($coin, $pnl))->value() : static fn($pnl) => (string)$pnl;
        $selectedCache !== null && $bottomCells[] = self::getFormattedDiff(a: $unrealisedTotal, b: $selectedCache['unrealizedTotal'], formatter: $pnlFormatter);
        $prevCache !== null && $bottomCells[] = self::getFormattedDiff(a: $unrealisedTotal, b: $prevCache['unrealizedTotal'], formatter: $pnlFormatter);
        $rows[] = DataRow::default($bottomCells);
        ### bottom END ###

        $headerColumns = [
            'symbol',
            'entry / size',
            'stops',
            'liq',
            'liq - entry',
            '=> % of entry',
            'liq - markPrice',
            '=> % of markPrice',
            'unrealized PNL',
        ];

        if ($selectedCache) {
            $headerColumns[] = 'Δ (cache)';
        }

        if ($prevCache) {
            $headerColumns[] = 'Δ (prev.)';
        }

        ConsoleTableBuilder::withOutput($this->output)
            ->withHeader($headerColumns)
            ->withRows(...$rows)
            ->build()
            ->setStyle('box-double')
            ->render();

        if ($this->paramFetcher->getBoolOption(self::SAVE_SORT_OPTION)) {
            $currentSymbolsSort = array_map(static fn (Symbol $symbol) => $symbol->value, $this->symbols);
            $item = $this->cache->getItem(self::SortCacheKey)->set($currentSymbolsSort)->expiresAfter(null);
            $this->cache->save($item);
        }
    }

    /**
     * @return array<DataRow|SeparatorRow>
     */
    private function posInfo(Symbol $symbol, float &$unrealizedTotal, array $specifiedCache = [], array $prevCache = []): array
    {
        $result = [];

        $positions = $this->positionService->getPositions($symbol);
        $ticker = $this->exchangeService->ticker($symbol);
        $this->cacheCollector[self::tickerCacheKey($ticker)] = $ticker;

        if (!$positions) {
            return [];
        }

        $hedge = $positions[0]->getHedge();
        $main = $hedge?->mainPosition ?? $positions[0];

        $mainPositionCacheKey = self::positionCacheKey($main);
        $this->cacheCollector[$mainPositionCacheKey] = $main;

        $liquidationDistance = $main->liquidationDistance();
        $distanceWithLiquidation = $main->priceDistanceWithLiquidation($ticker);

        $percentOfEntry = Percent::fromPart($liquidationDistance / $main->entryPrice, false)->setOutputDecimalsPrecision(7)->setOutputFloatPrecision(1);
        $percentOfMarkPrice = Percent::fromPart($distanceWithLiquidation / $ticker->markPrice->value(), false)->setOutputDecimalsPrecision(7)->setOutputFloatPrecision(1);

        $liqDiffColor = null;
        if ($percentOfMarkPrice->value() < $percentOfEntry->value()) {
            $diff = (($percentOfEntry->value() - $percentOfMarkPrice->value()) / $percentOfEntry->value()) * 100;
            $liqDiffColor = match (true) {
                $diff > 5 => Color::YELLOW,
                $diff > 15 => Color::BRIGHT_RED,
                $diff > 30 => Color::RED,
                default => null
            };
        }

        $mainPositionPnl = $main->unrealizedPnl;

        $stops = array_filter($this->stops, static function(Stop $stop) use ($main, $symbol) {
            $modifier = Percent::string('20%')->of($main->liquidationDistance());
            $bound = $main->isShort() ? $main->liquidationPrice()->sub($modifier) : $main->liquidationPrice()->add($modifier);

            return
                $stop->getPositionSide() === $main->side
                && $stop->getSymbol() === $symbol
                && $stop->getExchangeOrderId() === null
                && $symbol->makePrice($stop->getPrice())->isPriceInRange(PriceRange::create($main->entryPrice(), $bound, $symbol))
            ;
        });
        $stoppedVolume = (new StopsCollection(...$stops))->volumePart($main->size);

        $cells = [
            sprintf('%8s: %8s | %8s | %8s', $symbol->shortName(), $ticker->lastPrice, $ticker->markPrice, $ticker->indexPrice),
            sprintf(
                '%5s: %9s   | %6s',
                $main->side->title(),
                $main->entryPrice(),
                self::formatChangedValue(value: $main->size, specifiedCacheValue: (($specifiedCache[$mainPositionCacheKey] ?? null)?->size))
            ),
            new Percent($stoppedVolume, false),
            Cell::default($main->liquidationPrice()),
            $liquidationDistance,
            (string)$percentOfEntry,
            $distanceWithLiquidation,
            new Cell((string)$percentOfMarkPrice, $liqDiffColor ? new CellStyle(fontColor: $liqDiffColor) : null),
            new Cell(new CoinAmount($main->symbol->associatedCoin(), $mainPositionPnl), $mainPositionPnl < 0 ? new CellStyle(fontColor: Color::BRIGHT_RED) : null),
        ];

        $pnlFormatter = static fn(float $pnl) => (new CoinAmount($main->symbol->associatedCoin(), $pnl))->value();
        if ($specifiedCache) {
            $cachedValue = ($specifiedCache[$mainPositionCacheKey] ?? null)?->unrealizedPnl;
            $cells[] = $cachedValue !== null ? self::getFormattedDiff(a: $mainPositionPnl, b: $cachedValue, formatter: $pnlFormatter) : '';
        }
        if ($prevCache) {
            $cachedValue = ($prevCache[$mainPositionCacheKey] ?? null)?->unrealizedPnl;
            $cells[] = $cachedValue !== null ? self::getFormattedDiff(a: $mainPositionPnl, b: $cachedValue, formatter: $pnlFormatter) : '';
        }
        $result[] = DataRow::default($cells);

        $unrealizedTotal += $mainPositionPnl;

        if ($support = $main->getHedge()?->supportPosition) {
            $supportPnl = $support->unrealizedPnl;
            $result[] = DataRow::default([
                '',
                sprintf(' sup.: %9s   | %6s', $support->entryPrice(), self::formatChangedValue(value: $support->size, specifiedCacheValue: (($specifiedCache[self::positionCacheKey($support)] ?? null)?->size))),
                '',
                '',
                '',
                '',
                '',
                '',
                self::formatChangedValue(
                    value: $supportPnl,
                    specifiedCacheValue: (($specifiedCache[self::positionCacheKey($support)] ?? null)?->unrealizedPnl),
                    formatter: static fn (float $pnl) => (new CoinAmount($main->symbol->associatedCoin(), $pnl))->value(),
                    withoutColor: true
                ),
            ]);
            $unrealizedTotal += $supportPnl;
            $this->cacheCollector[self::positionCacheKey($support)] = $support;
        }

        $result[] = new SeparatorRow();

        return $result;
    }

    private function getCacheRecordToShowDiffWith(?array $lastCache): ?array
    {
        $cache = null;
        if ($this->showDiffWithOption) {
            $selectedDataKey = $this->showDiffWithOption;
            if ($selectedDataKey === 'last') {
                if ($lastCache) {
                    # in case of update enabled
                    return $lastCache;
                }

                assert($savedKeys = $this->getSavedDataCacheKeys(), new Exception('Saved cache not found'));
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

    private static function formatChangedValue(
        int|float $value,
        int|float|null $specifiedCacheValue = null,
        int|float|null $prevIterationValue = null,
        callable $formatter = null,
        ?bool $withoutColor = null
    ): string {
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

    private static function getFormattedDiff(int|float $a, int|float $b, ?bool $withoutColor = null, ?callable $formatter = null): ?string
    {
        $diff = $a - $b;

        if ($diff === 0.00 || $diff === 0) {
            return '-';
        }

        [$sign, $wrapper] = match (true) {
            $diff > 0 => ['+', 'green-text'],
            $diff < 0 => ['', 'red-text'],
            default => [null, null]
        };

        if ($withoutColor === true) {
            $wrapper = null;
        }

        $diff = $formatter($diff);
        return sprintf(
            '%s%s%s%s',
            $wrapper !== null ? sprintf('<%s>', $wrapper) : '',
            $sign !== null ? sprintf('%s', $sign) : '',
            $diff,
            $wrapper !== null ? sprintf('</%s>', $wrapper) : '',
        );
    }

    private static function positionCacheKey(Position $position): string {return sprintf('position_%s_%s', $position->symbol->value, $position->side->value);}
    private static function tickerCacheKey(Ticker $ticker): string {return sprintf('ticker_%s', $ticker->symbol->value);}

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
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
