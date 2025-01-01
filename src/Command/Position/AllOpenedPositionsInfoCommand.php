<?php

namespace App\Command\Position;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Domain\Coin\CoinAmount;
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

    private const SortCacheKey = 'opened_positions_sort';
    private const SavedDataKeysCacheKey = 'saved_data_cache_keys';

    private const WITH_SAVED_SORT_OPTION = 'sorted';
    private const SAVE_SORT_OPTION = 'save-sort';
    private const DIFF_WITH_SAVED_CACHE_OPTION = 'diff';
    private const REMOVE_PREVIOUS_CACHE_OPTION = 'remove-prev';

    private array $cacheCollector = [];

    protected function configure(): void
    {
        $this
            ->addOption(self::WITH_SAVED_SORT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Apply saved sort')
            ->addOption(self::SAVE_SORT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Save current sort')
            ->addOption(self::DIFF_WITH_SAVED_CACHE_OPTION, null, InputOption::VALUE_OPTIONAL, 'Output diff with saved cache')
            ->addOption(self::REMOVE_PREVIOUS_CACHE_OPTION, null, InputOption::VALUE_NEGATABLE, 'Remove previous cache')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->getFormatter()->setStyle('red-text', new OutputFormatterStyle(foreground: 'red', options: ['bold', 'blink']));
        $output->getFormatter()->setStyle('green-text', new OutputFormatterStyle(foreground: 'green', options: ['bold', 'blink']));

        $savedCachedDataItem = $this->cache->getItem(self::SavedDataKeysCacheKey);
        if ($savedCachedDataItem->isHit()) {
            OutputHelper::block('saved cache:', $savedCachedDataItem->get());
        }

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

        if ($savedDataKey = $this->paramFetcher->getStringOption(self::DIFF_WITH_SAVED_CACHE_OPTION, false)) {
            if ($savedDataKey === 'last') {
                if (!$savedKeys = $this->getSavedDataCacheKeys()) {
                    $this->io->error('Saved cache not found');
                    return Command::FAILURE;
                }
                $savedDataKey = $savedKeys[array_key_last($savedKeys)];
            }

            if (!($item = $this->cache->getItem($savedDataKey))->isHit()) {
                throw new Exception(sprintf('Cannot find cache for "%s"', $savedDataKey));
            }

            if ($this->paramFetcher->getBoolOption(self::REMOVE_PREVIOUS_CACHE_OPTION)) {
                $this->removeSavedDataCacheBefore($savedDataKey);
            }

            $cache = $item->get();
        }

        $unrealisedTotal = 0;
        $rows = [];
        foreach ($symbols as $symbol) {
            if ($symbolRows = $this->posInfo($symbol, $unrealisedTotal, $cache ?? [])) {
                $rows = array_merge($rows, $symbolRows);
            }
        }

        $this->cacheCollector['unrealizedTotal'] = $unrealisedTotal;
        $rows[] = DataRow::default([self::formatChangedValue($unrealisedTotal, $cache['unrealizedTotal'] ?? null)]);

        ConsoleTableBuilder::withOutput($this->output)
            ->withHeader([
                'symbol',
                'entry / size',
                'liq',
                'liq - entry',
                '=> % of entry',
                'liq - markPrice',
                '=> % of markPrice',
                'unrealized PNL',
            ])
            ->withRows(...$rows)
            ->build()
            ->setStyle('box-double')
            ->render();

        if ($this->paramFetcher->getBoolOption(self::SAVE_SORT_OPTION)) {
            $currentSymbolsSort = array_map(static fn (Symbol $symbol) => $symbol->value, $symbols);
            $item = $this->cache->getItem(self::SortCacheKey)->set($currentSymbolsSort)->expiresAfter(null);
            $this->cache->save($item);
        }

        # save data for further compare
        $cachedDataCacheKey = sprintf('opened_positions_data_cache_%s', $this->clock->now()->format('Y-m-d_H-i-s'));
        $item = $this->cache->getItem($cachedDataCacheKey)->set($this->cacheCollector)->expiresAfter(null);
        $this->cache->save($item);
        $this->addSavedDataCacheKey($cachedDataCacheKey);
        OutputHelper::print(sprintf('Cache saved as "%s"', $cachedDataCacheKey));

        return Command::SUCCESS;
    }

    /**
     * @return DataRow|SeparatorRow[]
     */
    private function posInfo(Symbol $symbol, float &$unrealizedTotal, array $cache = []): array
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
        $this->cacheCollector[self::positionCacheKey($main)] = $main;

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

        $mainPositionPnlContent = self::formatChangedValue(
            $mainPositionPnl,
            ($cache[self::positionCacheKey($main)] ?? null)?->unrealizedPnl,
            static fn (float $pnl) => (new CoinAmount($main->symbol->associatedCoin(), $pnl))->value()
        );

        $result[] = DataRow::default([
            sprintf('%8s: %8s | %8s | %8s', $symbol->shortName(), $ticker->lastPrice, $ticker->markPrice, $ticker->indexPrice),
            sprintf('%5s: %9s   | %6s', $main->side->title(), $main->entryPrice(), $main->size),
            Cell::default($main->liquidationPrice()),
            $liquidationDistance,
            (string)$percentOfEntry,
            $distanceWithLiquidation,
            new Cell((string)$percentOfMarkPrice, $liqDiffColor ? new CellStyle(fontColor: $liqDiffColor) : null),
            new Cell(($mainPositionPnlContent), $mainPositionPnl < 0 ? new CellStyle(fontColor: Color::BRIGHT_RED) : null),
        ]);

        $unrealizedTotal += $mainPositionPnl;

        if ($support = $main->getHedge()?->supportPosition) {
            $supportPnl = $support->unrealizedPnl;
            $result[] = DataRow::default([
                '',
                sprintf(' sup.: %9s   | %6s', $support->entryPrice(), $support->size),
                '',
                '',
                '',
                '',
                '',
                self::formatChangedValue($supportPnl, ($cache[self::positionCacheKey($support)] ?? null)?->unrealizedPnl, static fn (float $pnl) => (new CoinAmount($main->symbol->associatedCoin(), $pnl))->value()),
            ]);
            $unrealizedTotal += $supportPnl;
            $this->cacheCollector[self::positionCacheKey($support)] = $support;
        }

        $result[] = new SeparatorRow();

        return $result;
    }

    private static function formatChangedValue(int|float $value, int|float|null $prevValue = null, callable $formatter = null): string
    {
        $formatter = $formatter ?? static fn ($val) => (string)$val;
        $result = $formatter($value);

        if ($prevValue !== null && $value !== $prevValue) {
            $diff = $value - $prevValue;

            [$sign, $wrapper] = match (true) {
                $diff > 0 => ['+', 'green-text'],
                $diff < 0 => ['', 'red-text'],
                default => [null, null]
            };

            $diff = $formatter($diff);
            $result .= sprintf(' (%s%s%s)', $sign !== null ? sprintf('<%s>%s', $wrapper, $sign) : '', $diff, $wrapper !== null ? sprintf('</%s>', $wrapper) : '');
        }

        return $result;
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
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
