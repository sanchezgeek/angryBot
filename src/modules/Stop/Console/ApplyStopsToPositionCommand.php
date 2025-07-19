<?php

namespace App\Stop\Console;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\PositionDependentCommand;
use App\Domain\Trading\Enum\TradingStyle;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionEntryDto;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionHandler;
use App\Trading\Application\UseCase\OpenPosition\OrdersGrids\OpenPositionStopsGridsDefinitions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'p:stops:apply')]
class ApplyStopsToPositionCommand extends AbstractCommand implements PositionDependentCommand
{
    use ConsoleInputAwareCommand;
    use SymbolAwareCommand;
    use PositionAwareCommand;

    public const string TRADING_STYLE = 'style';

    private TradingStyle $tradingStyle;

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addArgument(self::TRADING_STYLE, InputArgument::OPTIONAL, 'Trading style', TradingStyle::Conservative->value)
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->tradingStyle = TradingStyle::from($this->paramFetcher->getStringArgument(self::TRADING_STYLE));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $position = $this->getPosition();
        $symbol = $position->symbol;
        $side = $position->side;
        $priceToRelate = $position->entryPrice();

        $stopsGridsDef = $this->stopsGridDefinitionFinder->create($symbol, $side, $priceToRelate, $this->tradingStyle);

        if (
            $stopsGridsDef?->isFoundAutomaticallyFromTa()
            && !$this->io->confirm(sprintf('Stops grid definition found automatically from TA: `%s`. Confirm?', $stopsGridsDef))
        ) {
            return Command::FAILURE;
        }

        $this->handler->handle(
            new ApplyStopsToPositionEntryDto(
                $symbol,
                $side,
                $position->size,
                $stopsGridsDef
            )
        );

        return Command::SUCCESS;
    }

    public function __construct(
        PositionServiceInterface $positionService,
        private readonly OpenPositionStopsGridsDefinitions $stopsGridDefinitionFinder,
        private readonly ApplyStopsToPositionHandler $handler,
        ?string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
