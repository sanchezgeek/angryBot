<?php

namespace App\Command\Position;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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

    const WITH_SAVED_SORT_OPTION = 'sorted';
    const SAVE_SORT_OPTION = 'save-sort';

    protected function configure(): void
    {
        $this
            ->addOption(self::WITH_SAVED_SORT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Apply saved sort')
            ->addOption(self::SAVE_SORT_OPTION, null, InputOption::VALUE_NEGATABLE, 'Save current sort')
        ;
    }

    private function getSymbolsSort(): ?array
    {
        $key = self::sortCacheKey();
        $item = $this->cache->getItem($key);

        if ($item->isHit()) {
            return $item->get();
        }

        return null;
    }

    private static function sortCacheKey(): string
    {
        return 'opened_positions_sort';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbols = $this->positionService->getOpenedPositionsSymbols();

        if ($this->paramFetcher->getBoolOption(self::WITH_SAVED_SORT_OPTION)) {
            $sort = $this->getSymbolsSort();
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

        $unrealised = 0;
        $rows = [];
        foreach ($symbols as $symbol) {
            if ($symbolRows = $this->posInfo($symbol, $unrealised)) {
                $rows = array_merge($rows, $symbolRows);
            }
        }

        $rows[] = DataRow::default([$unrealised]);

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
            $item = $this->cache->getItem(self::sortCacheKey())->set($currentSymbolsSort)->expiresAfter(null);
            $this->cache->save($item);
        }

        return Command::SUCCESS;
    }

    /**
     * @return DataRow|SeparatorRow[]
     */
    private function posInfo(Symbol $symbol, float &$unrealized): array
    {
        $result = [];

        $positions = $this->positionService->getPositions($symbol);
        $ticker = $this->exchangeService->ticker($symbol);

        if (!$positions) {
            return [];
        }

        $hedge = $positions[0]->getHedge();
        $main = $hedge?->mainPosition ?? $positions[0];

        $liquidationDistance = $main->liquidationDistance();
        $distanceWithLiquidation = $main->priceDistanceWithLiquidation($ticker);

        $percentOfEntry = Percent::fromPart($liquidationDistance / $main->entryPrice, false)->setOutputDecimalsPrecision(7)->setOutputFloatPrecision(1);
        $percentOfMarkPrice = Percent::fromPart($distanceWithLiquidation / $ticker->markPrice->value(), false)->setOutputDecimalsPrecision(7)->setOutputFloatPrecision(1);

        $liqDiffColor = null;
        if ($percentOfMarkPrice->value() < $percentOfEntry->value()) {
            $diff = $percentOfEntry->value() - $percentOfMarkPrice->value();

            $liqDiffColor = match (true) {
                $diff > 5 => Color::YELLOW,
                $diff > 15 => Color::BRIGHT_RED,
                $diff > 30 => Color::RED,
                default => null
            };
        }

        $result[] = DataRow::default([
            sprintf('%10s: %8s | %8s | %8s', $symbol->value, $ticker->lastPrice->value(), $ticker->markPrice, $ticker->indexPrice),
            sprintf('%5s: %9s   | %6s', $main->side->title(), $main->entryPrice(), $main->size),
            Cell::default($main->liquidationPrice()),
            $liquidationDistance,
            (string)$percentOfEntry,
            $distanceWithLiquidation,
            new Cell((string)$percentOfMarkPrice, $liqDiffColor ? new CellStyle(fontColor: $liqDiffColor) : null),
            new Cell((string)(new CoinAmount($main->symbol->associatedCoin(), $main->unrealizedPnl)), $main->unrealizedPnl < 0 ? new CellStyle(fontColor: Color::BRIGHT_RED) : null),
        ]);

        $unrealized += $main->unrealizedPnl;

        if ($support = $main->getHedge()?->supportPosition) {
            $result[] = DataRow::default([
                '',
                sprintf('sup.: %10s   | %6s', $support->entryPrice(), $support->size),
                '',
                '',
                '',
                '',
                '',
                new CoinAmount($main->symbol->associatedCoin(), $support->unrealizedPnl),
            ]);
            $unrealized += $support->unrealizedPnl;
        }

        $result[] = new SeparatorRow();

        return $result;
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
        private CacheInterface $cache,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
