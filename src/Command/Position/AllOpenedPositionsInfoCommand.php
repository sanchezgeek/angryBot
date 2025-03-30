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
use App\Command\Helper\ConsoleTableHelper as CTH;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Stop\StopsCollection;
use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
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

#[AsCommand(name: 'p:opened')]
class AllOpenedPositionsInfoCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;
    use PriceRangeAwareCommand;

    private const DEFAULT_UPDATE_INTERVAL = '15';
    private const DEFAULT_SAVE_CACHE_INTERVAL = '150';

    private const SortCacheKey = 'opened_positions_sort';
    private const SavedDataKeysCacheKey = 'saved_data_cache_keys';

    private const MOVE_HEDGED_UP_OPTION = 'move-hedged-up';
    private const WITH_SAVED_SORT_OPTION = 'sorted';
    private const SAVE_SORT_OPTION = 'save-sort';
    private const FIRST_ITERATION_SAVE_CACHE_COMMENT = 'comment';
    private const MOVE_UP_OPTION = 'move-up';
    private const DIFF_WITH_SAVED_CACHE_OPTION = 'diff';
    private const CURRENT_STATE_OPTION = 'current-state';
    private const REMOVE_PREVIOUS_CACHE_OPTION = 'remove-prev';
    private const SHOW_CACHE_OPTION = 'show-cache';
    private const UPDATE_OPTION = 'update';
    private const UPDATE_INTERVAL_OPTION = 'update-interval';
    private const SAVE_EVERY_N_ITERATION_OPTION = 'save-cache-interval';
    private const SHOW_FULL_TICKER_DATA_OPTION = 'show-full-ticker';

    private array $cacheCollector = [];
    private ?string $showDiffWithOption;
    private ?string $cacheKeyToUseAsCurrentState;

    /** @var Symbol[] */
    private array $symbols;

    /** @var Stop[] */
    private array $stops;

    /** @var array<Position[]> */
    private array $positions;

    /** @var array<string, Price> */
    private array $lastMarkPrices;

    private bool $currentSortSaved = false;

    protected function configure(): void
    {
        $this
            ->addOption(self::MOVE_HEDGED_UP_OPTION, null, InputOption::VALUE_NEGATABLE, 'Move fully-hedge positions up')
            ->addOption(self::WITH_SAVED_SORT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Apply saved sort')
            ->addOption(self::SAVE_SORT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Save current sort')
            ->addOption(self::MOVE_UP_OPTION, null, InputOption::VALUE_OPTIONAL, 'Move specified symbols up')
            ->addOption(self::DIFF_WITH_SAVED_CACHE_OPTION, null, InputOption::VALUE_OPTIONAL, 'Output diff with saved cache')
            ->addOption(self::FIRST_ITERATION_SAVE_CACHE_COMMENT, 'c', InputOption::VALUE_OPTIONAL, 'Comment on first cache save')
            ->addOption(self::CURRENT_STATE_OPTION, null, InputOption::VALUE_OPTIONAL, 'Use specified cached data as current state')
            ->addOption(self::REMOVE_PREVIOUS_CACHE_OPTION, null, InputOption::VALUE_NEGATABLE, 'Remove previous cache')
            ->addOption(self::UPDATE_OPTION, null, InputOption::VALUE_NEGATABLE, 'Update?', true)
            ->addOption(self::UPDATE_INTERVAL_OPTION, null, InputOption::VALUE_REQUIRED, 'Update interval', self::DEFAULT_UPDATE_INTERVAL)
            ->addOption(self::SAVE_EVERY_N_ITERATION_OPTION, null, InputOption::VALUE_REQUIRED, 'Number of iterations to wait before save current state', self::DEFAULT_SAVE_CACHE_INTERVAL)
            ->addOption(self::SHOW_CACHE_OPTION, null, InputOption::VALUE_NEGATABLE, 'Show cache records?')
            ->addOption(self::SHOW_FULL_TICKER_DATA_OPTION, null, InputOption::VALUE_NEGATABLE, 'Show full ticker data?')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->showDiffWithOption = $this->paramFetcher->getStringOption(self::DIFF_WITH_SAVED_CACHE_OPTION, false);
        $this->cacheKeyToUseAsCurrentState = $this->paramFetcher->getStringOption(self::CURRENT_STATE_OPTION, false);
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
            $iteration++;
            $this->cacheCollector = [];

            $cache = $this->getCacheRecordToShowDiffWith($previousIterationCache);

            $prevCache = null;
            if ($this->showDiffWithOption !== 'last') {
                $prevCache = $previousIterationCache;
            }

            $this->doOut($cache, $prevCache);

            $saveCacheComment = $this->paramFetcher->getStringOption(self::FIRST_ITERATION_SAVE_CACHE_COMMENT, false);

            $saveCurrentState =
                !$updateEnabled
                || $saveCacheComment
                || $iteration % $this->paramFetcher->getIntOption(self::SAVE_EVERY_N_ITERATION_OPTION) === 0;

            if ($saveCurrentState) {
                $cachedDataCacheKey = sprintf('opened_positions_%s', $this->clock->now()->format('Y-m-d_H-i-s'));
                if ($saveCacheComment) {
                    $cachedDataCacheKey .= '_' . $saveCacheComment;
                }
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

        $positionService = $this->positionService; /** @var ByBitLinearPositionService $positionService */
        $this->positions = $positionService->getAllPositions();
        $this->lastMarkPrices = $positionService->getLastMarkPrices();
        $symbols = $this->getOpenedPositionsSymbols();

        $singleCoin = null;
        foreach ($symbols as $symbol) {
            if ($singleCoin !== null && $symbol->associatedCoin() !== $singleCoin) {
                $singleCoin = null; break;
            }
            $singleCoin = $symbol->associatedCoin();
        }

        if ($singleCoin) {
            $balance = $this->exchangeAccountService->getContractWalletBalance($singleCoin);
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

        $this->cacheCollector['unrealizedTotal'] = $unrealisedTotal;

        ### bottom START ###
        $bottomCells = [Cell::default($this->clock->now()->format('D, d M H:i:s'))->setAlign(CellAlign::CENTER)];
        $balanceContent = isset($balance)
            ? sprintf('%s avail | %s free | %s total', self::formatPnl($balance->availableForTrade)->value(), self::formatPnl($balance->free)->value(), self::formatPnl($balance->total)->value())
            : '';
        $bottomCells[] = sprintf('% 48s', $balanceContent);
        $bottomCells[] = '';

        $pnlFormatter = $singleCoin ? static fn(float $pnl) => new CoinAmount($singleCoin, $pnl) : static fn($pnl) => (string)$pnl;
        $bottomCells[] = Cell::default($pnlFormatter($unrealisedTotal))->setAlign(CellAlign::RIGHT);
        $bottomCells[] = '';
        $pnlFormatter = $singleCoin ? static fn(float $pnl) => (new CoinAmount($singleCoin, $pnl))->value() : static fn($pnl) => (string)$pnl;
        $selectedCache !== null && $bottomCells[] = Cell::default(self::getFormattedDiff(a: $unrealisedTotal, b: $selectedCache['unrealizedTotal'], formatter: $pnlFormatter))->setAlign(CellAlign::RIGHT);
        $prevCache !== null && $bottomCells[] = Cell::default(self::getFormattedDiff(a: $unrealisedTotal, b: $prevCache['unrealizedTotal'], formatter: $pnlFormatter))->setAlign(CellAlign::RIGHT);

        $bottomCells = array_merge($bottomCells, ['', '', '', '']);
        $rows[] = DataRow::default($bottomCells);
        ### bottom END ###

        $headerColumns = [
            $this->paramFetcher->getBoolOption(self::SHOW_FULL_TICKER_DATA_OPTION) ? 'symbol (last / mark / index)' : 'symbol',
            'entry / liq / size',
            'PNL%',
            'PNL',
        ];
        $headerColumns[] = 'smb';
        $selectedCache && $headerColumns[] = 'cache';
        $prevCache && $headerColumns[] = 'prev';
        $headerColumns = array_merge($headerColumns, [
            'smb',
            "liq-ent.(\ninitial\n liq.\n distance\n)",
            "/ entry\n  price",
            "liq-mark(\n current\n liq.\n distance\n)",
            "/ entry\n  price",
            "smb",
            "liq.\ndistance\npassed",
            "stops(\n between liq.\n and entry\n)\n[auto/manual]",
        ]);

        ConsoleTableBuilder::withOutput($this->output)
            ->withHeader($headerColumns)
            ->withRows(...$rows)
            ->build()
            ->setStyle('box')
            ->render();
    }

    private function prepareStoppedPartContent(Position $position, $markPrice): ?string
    {
        $symbol = $position->symbol;
        $positionSide = $position->side;

        $stops = $this->stopRepository->findActive(
            symbol: $symbol,
            side: $positionSide,
            qbModifier: static fn(QB $qb) => QueryHelper::addOrder($qb, 'price', $positionSide->isShort() ? 'ASC' : 'DESC'),
        );
        /** @var Stop[] $autoStops */
        $autoStops = array_filter($stops, static function(Stop $stop) use ($position, $symbol, $markPrice) {
            if (!$position->getHedge() || $position->isMainPosition()) {
                $modifier = Percent::string('20%')->of($position->liquidationDistance());
                $bound = $position->isShort() ? $position->liquidationPrice()->sub($modifier) : $position->liquidationPrice()->add($modifier);
                if (!($symbol->makePrice($stop->getPrice())->isPriceInRange(PriceRange::create($markPrice, $bound, $symbol)))) {
                    return false;
                }
            }

            return $stop->isAdditionalStopFromLiquidationHandler();
        });
        /** @var Stop[] $manualStops */
        $manualStops = array_filter($stops, static function(Stop $stop) use ($position, $symbol, $markPrice) {
            if (!$position->getHedge() || $position->isMainPosition()) {
                $modifier = Percent::string('20%')->of($position->liquidationDistance());
                $bound = $position->isShort() ? $position->liquidationPrice()->sub($modifier) : $position->liquidationPrice()->add($modifier);
                if (!($symbol->makePrice($stop->getPrice())->isPriceInRange(PriceRange::create($markPrice, $bound, $symbol)))) {
                    return false;
                }
            }

            return !$stop->isAdditionalStopFromLiquidationHandler();
        });

        $stoppedVolume = [];
        $entryPrice = $position->entryPrice();
        if ($manualStops) {
            $manualStoppedPartPct = (new Percent((new StopsCollection(...$manualStops))->volumePart($position->size), false))->setOutputFloatPrecision(1);
            $firstManualStop = $manualStops[array_key_first($manualStops)];
            $distancePnlPct = PnlHelper::convertAbsDeltaToPnlPercentOnPrice(
                $entryPrice->differenceWith($symbol->makePrice($firstManualStop->getPrice()))->deltaForPositionLoss($positionSide),
                $entryPrice
            );
            $stoppedVolume[] = sprintf('%s|%d[%s.%s]', $manualStoppedPartPct, count($manualStops), CTH::colorizeText('m', 'yellow-text'), $distancePnlPct->setOutputFloatPrecision(1));
        }

        if ($autoStops) {
            $autoStoppedPartPct = (new Percent((new StopsCollection(...$autoStops))->volumePart($position->size), false))->setOutputFloatPrecision(1);
            $firstAutoStop = $autoStops[array_key_first($autoStops)];
            $distancePnlPct = PnlHelper::convertAbsDeltaToPnlPercentOnPrice(
                $entryPrice->differenceWith($symbol->makePrice($firstAutoStop->getPrice()))->deltaForPositionLoss($positionSide),
                $entryPrice
            );
            $stoppedVolume[] = sprintf('%s|%d[a.%s]', $autoStoppedPartPct, count($autoStops), $distancePnlPct->setOutputFloatPrecision(1));
        }

        return $stoppedVolume ? implode(' / ', $stoppedVolume) : null;
    }

    /**
     * @return array<DataRow|SeparatorRow>
     */
    private function posInfo(Symbol $symbol, float &$unrealizedTotal, array $specifiedCache = [], array $prevCache = []): array
    {
        $result = [];

        $positions = $this->positions[$symbol->value];
        if (!$positions) {
            return [];
        }

        $lastMarkPrices = $this->lastMarkPrices;
        $markPrice = $lastMarkPrices[$symbol->value];

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

        $initialLiquidationDistance = $main->liquidationDistance();
        $distanceBetweenLiquidationAndTicker = $main->liquidationPrice()->deltaWith($markPrice);

        $initialLiquidationDistancePercentOfEntry = Percent::fromPart($initialLiquidationDistance / $main->entryPrice, false)->setOutputDecimalsPrecision(7)->setOutputFloatPrecision(1);
        $distanceBetweenLiquidationAndTickerPercentOfEntry = Percent::fromPart($distanceBetweenLiquidationAndTicker / $main->entryPrice, false)->setOutputDecimalsPrecision(7)->setOutputFloatPrecision(1);
        if ($distanceBetweenLiquidationAndTickerPercentOfEntry->value() < $initialLiquidationDistancePercentOfEntry->value()) {
            $passedLiquidationDistancePercent = Percent::fromPart(($initialLiquidationDistancePercentOfEntry->value() - $distanceBetweenLiquidationAndTickerPercentOfEntry->value()) / $initialLiquidationDistancePercentOfEntry->value());
            $passedLiquidationDistancePercent = match (true) {
                $passedLiquidationDistancePercent->value() > 5 => CTH::colorizeText((string)$passedLiquidationDistancePercent, 'yellow-text'),
                $passedLiquidationDistancePercent->value() > 15 => CTH::colorizeText((string)$passedLiquidationDistancePercent, 'bright-red-text'),
                $passedLiquidationDistancePercent->value() > 30 => CTH::colorizeText((string)$passedLiquidationDistancePercent, 'red-text'),
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
            $cells = [sprintf('%8s: %10s', $symbol->shortName(), $markPrice)];
        }

        $cells = array_merge($cells, [
            sprintf(
                '%s: %9s    %9s     %6s',
                CTH::colorizeText(sprintf('%5s', $main->side->title()), $main->isShort() ? 'red-text' : 'green-text'),
                $main->entryPrice(),
                $liquidationContent,
                self::formatChangedValue(value: $main->size, specifiedCacheValue: (($specifiedCache[$mainPositionCacheKey] ?? null)?->size), formatter: static fn($value) => $symbol->roundVolume($value)),
            ),
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

        $text = $isEquivalentHedge ? strtolower($symbol->veryShortName()) : $symbol->veryShortName();
        $extraSymbolCell = CTH::colorizeText($text, $isEquivalentHedge ? 'none' : ($main->isShort() ? 'bright-red-text' : 'green-text'));
        $cells[] = $extraSymbolCell;

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
            $initialLiquidationDistance,
            (string)$initialLiquidationDistancePercentOfEntry,
            $distanceBetweenLiquidationAndTicker,
            $distanceBetweenLiquidationAndTickerPercentOfEntry,
            $extraSymbolCell,
            !$isEquivalentHedge ? ($passedLiquidationDistancePercent ?? '') : '',
            $stoppedVolume ?? '',
        ]);

        $result[$main->side->value] = DataRow::default($cells);

        $unrealizedTotal += $mainPositionPnl;

        if ($support) {
            $supportPnl = $support->unrealizedPnl;
            $stoppedVolume = $this->prepareStoppedPartContent($support, $markPrice);

            $cells = [
                '',
                sprintf(
                    ' sup.: %9s    %s     %6s',
//                    ' sup.: %9s                  %6s',
                    $support->entryPrice(),
                    CTH::colorizeText(sprintf('%9s', $support->getHedge()->getSupportRate()->setOutputFloatPrecision(1)), 'light-yellow-text'),
                    self::formatChangedValue(value: $support->size, specifiedCacheValue: (($specifiedCache[$supportPositionCacheKey] ?? null)?->size), formatter: static fn($value) => $symbol->roundVolume($value)),
                ),
            ];

            $cells[] = Cell::default((new Percent($markPrice->getPnlPercentFor($support), false))->setOutputFloatPrecision(1))->setAlign(CellAlign::RIGHT);
            $supportPnlContent = (string) self::formatPnl(new CoinAmount($symbol->associatedCoin(), $supportPnl));
            $supportPnlContent = !$isEquivalentHedge && $supportPnl < 0 ? CTH::colorizeText($supportPnlContent, 'yellow-text') : $supportPnlContent;

            $cells[] = new Cell($supportPnlContent, new CellStyle(fontColor: Color::WHITE));
            $cells[] = '';

            if ($specifiedCache) {
                if ($supportPnlSpecifiedCacheValue !== null) {
                    $cells[] = self::formatPnlDiffCell($symbol, false, $supportPnl, $supportPnlSpecifiedCacheValue, fontColor: Color::WHITE);
                } else {
                    $cells[] = '';
                }
            }
            if ($prevCache) {
                if ($supportPnlPrevCacheValue !== null) {
                    $cells[] = self::formatPnlDiffCell($symbol, false, $supportPnl, $supportPnlPrevCacheValue, fontColor: Color::WHITE);
                } else {
                    $cells[] = '';
                }
            }

            $cells = array_merge($cells, ['', '', '', '',  '', '', '', $stoppedVolume ?? '']);

            $result[$support->side->value] = DataRow::default($cells);

            $unrealizedTotal += $supportPnl;
            $this->cacheCollector[$supportPositionCacheKey] = $support;
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

    private static function formatPnlDiffCell(
        Symbol $symbol,
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
        $moveHedgedUp = $this->paramFetcher->getBoolOption(self::MOVE_HEDGED_UP_OPTION);

        $symbolsRaw = [];
        $equivalentHedgedSymbols = [];
        foreach ($this->positions as $symbolRaw => $symbolPositions) {
            $symbolsRaw[] = $symbolRaw;
            $symbol = Symbol::from($symbolRaw);
            if ($moveHedgedUp && $symbolPositions[array_key_first($symbolPositions)]?->getHedge()?->isEquivalentHedge()) {
                $equivalentHedgedSymbols[] = $symbolRaw;
            }
        }

        if ($this->paramFetcher->getBoolOption(self::WITH_SAVED_SORT_OPTION)) {
            $sort = ($item = $this->cache->getItem(self::SortCacheKey))->isHit() ? $item->get() : null;
            if ($sort === null) {
                OutputHelper::print('Saved sort not found');
            } else {
                $newPositionsSymbols = array_diff($symbolsRaw, $sort);
                $symbolsRawSorted = array_intersect($sort, $symbolsRaw);
                $symbolsRawSorted = array_merge($symbolsRawSorted, $newPositionsSymbols);
                $symbolsRaw = $symbolsRawSorted;
            }
        }

        if ($moveUpOption = $this->paramFetcher->getStringOption(self::MOVE_UP_OPTION, false)) {
            $providedItems = self::parseProvidedSymbols($moveUpOption);
            if ($providedItems) {
                $providedItems = array_map(static fn (Symbol $symbol) => $symbol->value, $providedItems);
                $providedItems = array_intersect($providedItems, $symbolsRaw);
                if ($providedItems) {
                    $symbolsRaw = array_merge($providedItems, array_diff($symbolsRaw, $providedItems));
                }
            }
        }

        if (!$this->currentSortSaved && $this->paramFetcher->getBoolOption(self::SAVE_SORT_OPTION)) {
            $symbols = array_map(static fn (string $symbolRaw) => Symbol::from($symbolRaw), $symbolsRaw);
            $currentSymbolsSort = array_map(static fn (Symbol $symbol) => $symbol->value, $symbols);
            $item = $this->cache->getItem(self::SortCacheKey)->set($currentSymbolsSort)->expiresAfter(null);
            $this->cache->save($item);
            $this->currentSortSaved = true;
        }

        if ($moveHedgedUp) {
            $symbolsRaw = array_merge($equivalentHedgedSymbols, array_diff($symbolsRaw, $equivalentHedgedSymbols));
        }

        return array_map(static fn (string $symbolRaw) => Symbol::from($symbolRaw), $symbolsRaw);
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

    private static function getFormattedDiff(
        int|float $a,
        int|float $b,
        ?bool $withoutColor = null,
        ?callable $formatter = null,
        bool $alreadySigned = false
    ): string {
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
