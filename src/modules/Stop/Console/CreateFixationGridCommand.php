<?php

namespace App\Stop\Console;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\SymbolDependentCommand;
use App\Domain\Value\Percent\Percent;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionEntryDto;
use App\Stop\Application\UseCase\ApplyStopsGrid\ApplyStopsToPositionHandler;
use App\Trading\Application\UseCase\OpenPosition\OrdersGrids\OpenPositionStopsGridsDefinitions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'p:fixation')]
class CreateFixationGridCommand extends AbstractCommand implements SymbolDependentCommand
{
    use ConsoleInputAwareCommand;
    use SymbolAwareCommand;

    public const string POSITION_PART_ARGUMENT = 'part';

    private Percent $positionPart;

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs()
            ->addArgument(self::POSITION_PART_ARGUMENT, InputArgument::REQUIRED, 'P osition part')
//            ->addOption(self::TYPE_OPTION, null, InputOption::VALUE_REQUIRED, sprintf('Type (%s)', EnumHelper::toArrayOfStrings(StopsApplyType::class)), StopsApplyType::Grid->value);
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->positionPart = Percent::string($this->paramFetcher->getPercentArgument(self::POSITION_PART_ARGUMENT));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        var_dump($this->positionPart);die;

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
