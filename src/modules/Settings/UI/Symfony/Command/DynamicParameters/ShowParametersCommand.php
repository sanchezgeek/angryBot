<?php

namespace App\Settings\UI\Symfony\Command\DynamicParameters;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\Exception\LiquidationDynamicParametersNotApplicapleException;
use App\Bot\Domain\Position;
use App\Command\AbstractCommand;
use App\Command\Helper\ConsoleTableHelper;
use App\Command\Helper\ConsoleTableHelper as CTH;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\SymbolDependentCommand;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Output\Table\Dto\Cell;
use App\Output\Table\Dto\DataRow;
use App\Output\Table\Dto\SeparatorRow;
use App\Output\Table\Dto\Style\Enum\CellAlign;
use App\Output\Table\Formatter\ConsoleTableBuilder;
use App\Settings\Application\DynamicParameters\AppDynamicParametersLocator;
use App\Settings\Application\DynamicParameters\Evaluation\AppDynamicParameterEvaluationEntry;
use App\Settings\Application\DynamicParameters\Evaluation\AppDynamicParameterEvaluator;
use App\Worker\AppContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'parameters:show')]
class ShowParametersCommand extends AbstractCommand implements SymbolDependentCommand
{
    use SymbolAwareCommand;

    private const string PARAMETER_NAME_ARG = 'parameter';
    private const string ALL_POSITIONS_OPTION = 'allPositions';

    private bool $showAllParameters;

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs(defaultValue: null)
            ->addArgument(self::PARAMETER_NAME_ARG, InputArgument::OPTIONAL)
            ->addOption(self::ALL_POSITIONS_OPTION, null, InputOption::VALUE_NEGATABLE, false)
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        CTH::registerColors($output);

        $this->showAllParameters = $this->paramFetcher->getBoolOption(self::ALL_POSITIONS_OPTION);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->parametersLocator->initialize();
        AppContext::setIsParametersEvaluationContext();

        if ($this->showAllParameters) {
            if ($this->symbolIsSpecified()) {
                $symbols = $this->getSymbols();
            } else {
                $symbols = $this->positionService->getOpenedPositionsSymbols();
            }

            $positions = $this->positionService->getAllPositions();
            $lastMarkPrices = $this->positionService->getLastMarkPrices();
            ['constructorsArguments' => $constructorsArguments, 'methodsArguments' => $methodsArguments] = $this->parameterEvaluator->getArgumentsToEvaluateAllParameters();
            $commonUserInput = [];

            $printGroups = [];

            foreach ($symbols as $symbol) {
                $symbolPositions = $positions[$symbol->name()];
                $symbolPositions = array_combine(array_map(static fn(Position $position) => $position->side->value, $symbolPositions), $symbolPositions);
                $symbolPositions = array_merge(array_flip([Side::Sell->value, Side::Buy->value]), $symbolPositions);
                $symbolPositions = array_filter($symbolPositions, static fn($item) => $item instanceof Position);

                foreach ($symbolPositions as $position) {
                    $side = $position->side;

                    $groupCaption = [
                        $symbol->name(),
                        $side->value,
                    ];
                    $groupCaption = sprintf('%s (markPrice=%s)', implode(' ', $groupCaption), $lastMarkPrices[$symbol->name()]);

                    foreach ($this->parametersLocator->getRegisteredParametersByGroups() as ['name' => $groupName, 'items' => $parameters]) {
                        foreach ($parameters as $parameterName) {
                            $userInput = array_merge($commonUserInput, [
                                'side' => $side->value,
                                'symbol' => $symbol->name(),
                            ]);

                            $constructorInput = [];
                            foreach ($constructorsArguments as $argumentName => $title) {
                                $constructorInput[$argumentName] = array_key_exists($argumentName, $userInput) ? $userInput[$argumentName] : $this->parseInputValue(
                                    $argumentName,
                                    $io->ask(sprintf('%s (from constructor): ', $title))
                                );

                                if (!array_key_exists($argumentName, $commonUserInput)) {
                                    $commonUserInput[$argumentName] = $constructorInput[$argumentName];
                                }
                            }

                            $methodInput = [];
                            foreach ($methodsArguments as $argumentName => $title) {
                                $methodInput[$argumentName] = array_key_exists($argumentName, $userInput) ? $userInput[$argumentName] : $this->parseInputValue(
                                    $argumentName,
                                    $io->ask(sprintf('%s: ', $title))
                                );

                                if (!array_key_exists($argumentName, $commonUserInput)) {
                                    $commonUserInput[$argumentName] = $methodInput[$argumentName];
                                }
                            }

                            try {
                                $value = $this->parameterEvaluator->evaluate(
                                    new AppDynamicParameterEvaluationEntry($groupName, $parameterName, $methodInput, $constructorInput)
                                );
                            } catch (LiquidationDynamicParametersNotApplicapleException) {
                                continue;
                            } catch (Throwable $e) {
                                $value = sprintf('!!! %s !!!', $e->getMessage());
                            }

                            $printGroups[$groupCaption][$groupName][sprintf('%70s', $groupName . '.' . $parameterName)] = $value;
                        }
                    }
                }
            }

            foreach ($printGroups as $groupCaption => $groupValues) {
                $this->printGroup($groupCaption, $groupValues);
                echo PHP_EOL;
            }
        } else {
            if ($name = $this->paramFetcher->getStringArgument(self::PARAMETER_NAME_ARG)) {
                $explode = explode('.', $name);
                $selectedGroup = $explode[0];
                $selectedParameter = $explode[1];
            } else {
                $parametersGroups = $this->parametersLocator->getRegisteredParametersByGroups();

                $rows = [];
                foreach ($parametersGroups as $groupKey => $group) {
                    $groupName = $group['name'];
                    $parameters = $group['items'];
                    $rows[] = DataRow::separated([Cell::restColumnsMerged(sprintf('%s %s', $groupName, $groupKey))->setAlign(CellAlign::RIGHT)]);
                    foreach ($parameters as $parameterKey => $parameterName) {
                        $rows[] = DataRow::separated([Cell::restColumnsMerged(sprintf('%s %s', $parameterKey, $parameterName))]);
                    }
                }

                ConsoleTableBuilder::withOutput($this->output)
                    ->withRows(...$rows)
                    ->build()
                    ->setStyle('box')
                    ->render();

                $groupKey = $io->ask('Group:');
                $parameterKey = $io->ask('Parameter:');

                $selectedGroup = $parametersGroups[$groupKey]['name'];
                $selectedParameter = $parametersGroups[$groupKey]['items'][$parameterKey];

                $this->io->info(sprintf('Selected parameter: %s.%s', $selectedGroup, $selectedParameter));
            }

            $userInput = [];
            if ($this->symbolIsSpecified()) {
                $userInput['symbol'] = $this->getSymbol()->name();
            }

            $arguments = $this->parameterEvaluator->getParameterArguments($selectedGroup, $selectedParameter);

            $constructorInput = [];
            foreach ($arguments['constructorArguments'] as $argumentName => $title) {
                $constructorInput[$argumentName] = $userInput[$argumentName] ?? $this->parseInputValue($argumentName, $io->ask(sprintf('%s (from constructor): ', $title)));
                if (!array_key_exists($argumentName, $userInput)) {
                    $userInput[$argumentName] = $constructorInput[$argumentName];
                }
            }

            $methodInput = [];
            foreach ($arguments['referencedMethodArguments'] as $argumentName => $title) {
                $methodInput[$argumentName] = $userInput[$argumentName] ?? $this->parseInputValue($argumentName, $io->ask(sprintf('%s: ', $title)));
                if (!array_key_exists($argumentName, $userInput)) {
                    $userInput[$argumentName] = $methodInput[$argumentName];
                }
            }

            $value = $this->parameterEvaluator->evaluate(
                new AppDynamicParameterEvaluationEntry($selectedGroup, $selectedParameter, $methodInput, $constructorInput)
            );

            $groupCaption = [];
            if (isset($userInput['symbol'])) {
                $groupCaption[] = $this->parseProvidedSingleSymbolAnswer($userInput['symbol']);
            }
            if (isset($userInput['side']) && $side = Side::tryFrom($userInput['side'])) {
                $groupCaption[] = $side->value;
            }
            $groupCaption = $groupCaption ? sprintf('%s', implode(' ', $groupCaption)) : null;

            $groupValues[$selectedGroup][sprintf('%70s', $selectedGroup . '.' . $selectedParameter)] = $value;

            $this->printGroup($groupCaption, $groupValues);
        }

        return Command::SUCCESS;
    }

    private function printGroup(?string $caption, array $groupValues): void
    {
//        if (!$this->showAllParameters) {
//            $io->block(sprintf('var_export: %s', var_export($value, true)));
//        }

        $tableBuilder = ConsoleTableBuilder::withOutput($this->output);
        $rows = [];

        if ($caption) {
            $rows[] = DataRow::default([Cell::colspan(2, ConsoleTableHelper::colorizeText($caption, 'green-text'))]);
            $rows[] = new SeparatorRow();
        }

        $lastParametersGroup = array_key_last($groupValues);
        $prevParameterGroupName = null;
        foreach ($groupValues as $parameterGroupName => $parameterGroupValues) {
            if ($prevParameterGroupName !== null && $prevParameterGroupName !== $parameterGroupName) {
                $rows[] = new SeparatorRow();
            }
            foreach ($parameterGroupValues as $name => $value) {
                $rows[] = DataRow::default([
                    Cell::align(CellAlign::RIGHT, $name),
                    Cell::default($value),
                ]);
            }
            $prevParameterGroupName = $parameterGroupName;
        }

        $tableBuilder
            ->withRows(...$rows)
            ->build()
            ->render();
    }

    private function parseInputValue(string $argumentName, mixed $input): mixed
    {
        if ($argumentName === 'symbol') {
            return $this->parseProvidedSingleSymbolAnswer($input)->name();
        }

        return match ($input) {
            'false' => false,
            'true' => true,
            default => $input
        };
    }

    public function __construct(
        private readonly AppDynamicParametersLocator $parametersLocator,
        private readonly AppDynamicParameterEvaluator $parameterEvaluator,
        private readonly ByBitLinearPositionService $positionService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
