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
use App\Output\Table\Formatter\ConsoleTableBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
            if ($row = $this->posInfo($symbol)) {
                $rows[] = $row;
            }
        }

        ConsoleTableBuilder::withOutput($this->output)
            ->withHeader([
                'symbol',
                'ticker.last',
                'entry',
                'size',
                'liq.price',
                'liq.price - entry',
                '=> % of entry',
                'liq.price - markPrice',
                '=> % of markPrice',
                'unrealized PNL',
            ])
            ->withRows(...$rows)
            ->build()
            ->render();

        return Command::SUCCESS;
    }

    private function posInfo(Symbol $symbol): ?DataRow
    {
        $positions = $this->positionService->getPositions($symbol);
        $ticker = $this->exchangeService->ticker($symbol);

        if (!$positions) {
            return null;
        }

        $hedge = $positions[0]->getHedge();
        $mainPosition = $hedge?->mainPosition ?? $positions[0];

        $liquidationDistance = $mainPosition->liquidationDistance();
        $distanceWithLiquidation = $mainPosition->priceDistanceWithLiquidation($ticker);

        return DataRow::default([
            $symbol->value,
            (string)$ticker->lastPrice->value(),
            $mainPosition->entryPrice(),
            $mainPosition->size,
            $mainPosition->liquidationPrice(),
            $liquidationDistance,
            (string)Percent::fromPart($liquidationDistance / $mainPosition->entryPrice, false),
            $distanceWithLiquidation,
            (string)Percent::fromPart($distanceWithLiquidation / $ticker->markPrice->value(), false),
            $mainPosition->unrealizedPnl,
        ]);
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
