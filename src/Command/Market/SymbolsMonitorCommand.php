<?php

namespace App\Command\Market;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Domain\Price\Price;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

#[AsCommand(name: 'symbols:monitor')]
class SymbolsMonitorCommand extends AbstractCommand
{
    use PositionAwareCommand;

    private const DEFAULT_UPDATE_INTERVAL = '8';
    private const UPDATE_INTERVAL_OPTION = 'update-interval';
    private const DEFAULT_COLUMNS_COUNT = '6';
    private const COLUMNS_COUNT_OPTION = 'columns-count';

    protected function configure(): void
    {
        $this
            ->addOption(self::UPDATE_INTERVAL_OPTION, null, InputOption::VALUE_REQUIRED, 'Update interval', self::DEFAULT_UPDATE_INTERVAL)
            ->addOption(self::COLUMNS_COUNT_OPTION, null, InputOption::VALUE_REQUIRED, 'Columns count', self::DEFAULT_COLUMNS_COUNT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        while(true) {
            $this->doOut();
            sleep($this->paramFetcher->getIntOption(self::UPDATE_INTERVAL_OPTION));
        }
    }

    private function doOut(): void
    {
        $columnsCount = $this->paramFetcher->getintOption(self::COLUMNS_COUNT_OPTION);
        $symbols = $this->positionService->getOpenedPositionsSymbols();

        $items = [];
        foreach ($symbols as $symbol) {
            $ticker = $this->exchangeService->ticker($symbol);
            $items[] = [$symbol, $ticker->indexPrice];
        }

        /** @var array<array<Symbol, Price>> $itemsRows */
        $itemsRows = array_chunk($items, $columnsCount);

        $namesLengths = [];
        $pricesLengths = [];
        for ($column = 0; $column < $columnsCount; $column++) {
            $names = $values = [];
            foreach ($itemsRows as $row) {
                if (!isset($row[$column])) {
                    continue;
                }
                $names[] = strlen($row[$column][0]->shortName());
                $values[] = strlen($row[$column][1]);
            }
            $namesLengths[$column] = max($names);
            $pricesLengths[$column] = max($values);
        }

        $out = [];
        foreach ($itemsRows as $row) {
            $items = [];
            foreach ($row as $column => [$symbol, $price]) {
                $items[] = sprintf('%' . $namesLengths[$column] . 's %' . $pricesLengths[$column] . 's', $symbol->shortName(), $price);
            }
            $out[] = implode(' ', $items) . "\n";
        }

        echo implode("", $out) . "\n";
    }

    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
