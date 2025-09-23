<?php

namespace App\TechnicalAnalysis\Console;

use App\Command\AbstractCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\SymbolDependentCommand;
use App\Domain\Trading\Enum\TimeFrame;
use App\Helper\OutputHelper;
use App\TechnicalAnalysis\Application\Service\Candles\PreviousCandlesProvider;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ta:candles:previous')]
class GetPreviousCandlesCommand extends AbstractCommand implements SymbolDependentCommand
{
    use SymbolAwareCommand;

    private const string TIMEFRAME_OPTION = 'timeframe';
    private const string DEFAULT_TIMEFRAME = '1D';

    private const string PERIOD_ARG = 'period';

    private SymbolInterface $symbol;

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs()
            ->addOption(self::TIMEFRAME_OPTION, null, InputOption::VALUE_REQUIRED, 'Timeframe', self::DEFAULT_TIMEFRAME)
            ->addArgument(self::PERIOD_ARG, InputArgument::REQUIRED)
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->symbol = $this->getSymbol();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $period = $this->paramFetcher->getIntArgument(self::PERIOD_ARG);
        $timeframe = TimeFrame::from($this->paramFetcher->getStringOption(self::TIMEFRAME_OPTION));

        $candles = $this->previousCandlesProvider->getPreviousCandles($this->symbol, $timeframe, $period);

        OutputHelper::print($candles);

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly PreviousCandlesProvider $previousCandlesProvider,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
