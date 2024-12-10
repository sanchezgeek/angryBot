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
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Infrastructure\Cache\PositionsCache;
use App\Output\Table\Dto\DataRow;
use App\Output\Table\Dto\SeparatorRow;
use App\Output\Table\Formatter\ConsoleTableBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_merge;
use function sprintf;

#[AsCommand(name: 'p:opened')]
class AllOpenedPositionsInfoCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;
    use PriceRangeAwareCommand;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbols = $this->positionService->getOpenedPositionsSymbols();
        $symbols[] = Symbol::BTCUSDT;

        $rows = [];
        foreach ($symbols as $symbol) {
            if ($symbolRows = $this->posInfo($symbol)) {
                $rows = array_merge($rows, $symbolRows);
            }
        }

        ConsoleTableBuilder::withOutput($this->output)
            ->withHeader([
                'symbol',
                'entry / size',
                'liq.price',
                'liq.price - entry',
                '=> % of entry',
                'liq.price - markPrice',
                '=> % of markPrice',
                'unrealized PNL',
            ])
            ->withRows(...$rows)
            ->build()
            ->setStyle('box-double')
            ->render();

        return Command::SUCCESS;
    }

    /**
     * @return DataRow|SeparatorRow[]
     */
    private function posInfo(Symbol $symbol): array
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

        $result[] = DataRow::default([
            sprintf('%10s: %8s | %8s | %8s', $symbol->value, $ticker->lastPrice->value(), $ticker->markPrice, $ticker->indexPrice),
            sprintf('%13s   / %5s', $main->entryPrice(), $main->size),
            $main->liquidationPrice(),
            $liquidationDistance,
            (string)Percent::fromPart($liquidationDistance / $main->entryPrice, false),
            $distanceWithLiquidation,
            (string)Percent::fromPart($distanceWithLiquidation / $ticker->markPrice->value(), false),
            $main->unrealizedPnl,
        ]);

//        if ($support = $main->getHedge()?->supportPosition) {
//            $result[] = DataRow::default([
//                '',
//                sprintf('sup.: %7s   / %5s', $support->entryPrice(), $support->size),
//                '',
//                '',
//                '',
//                '',
//                '',
//                $support->unrealizedPnl
//            ]);
//        }

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
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
