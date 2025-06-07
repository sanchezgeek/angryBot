<?php

namespace App\Settings\UI\Symfony\Command\DynamicParameters;

use App\Command\AbstractCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\SymbolDependentCommand;
use App\Output\Table\Dto\Cell;
use App\Output\Table\Dto\DataRow;
use App\Output\Table\Dto\Style\Enum\CellAlign;
use App\Output\Table\Formatter\ConsoleTableBuilder;
use App\Settings\Application\DynamicParameters\AppDynamicParametersLocator;
use App\Settings\Application\DynamicParameters\Evaluation\AppDynamicParameterEvaluationEntry;
use App\Settings\Application\DynamicParameters\Evaluation\AppDynamicParameterEvaluator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsCommand(name: 'parameters:show')]
#[AutoconfigureTag(name: 'command.symbol_dependent')]
class ShowParametersCommand extends AbstractCommand implements SymbolDependentCommand
{
    use SymbolAwareCommand;

    private const PARAMETER_NAME_ARG = 'parameter';

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs(defaultValue: null)
            ->addArgument(self::PARAMETER_NAME_ARG, InputArgument::OPTIONAL)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->parametersLocator->initialize();
        $name = $this->paramFetcher->getStringArgument(self::PARAMETER_NAME_ARG);

        if (!$name) {
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
        } else {
            $explode = explode('.', $name);
            $selectedGroup = $explode[0];
            $selectedParameter = $explode[1];
        }

        $userInput = [];
        if ($this->symbolIsSpecified()) {
            $userInput['symbol'] = $this->getSymbol()->name();
        }

        $arguments = $this->parameterEvaluator->getParameterArguments($selectedGroup, $selectedParameter);

        $constructorInput = [];
        foreach ($arguments['constructorArguments'] as $argumentName) {
            $constructorInput[$argumentName] = $userInput[$argumentName] ?? $this->parseInputValue($argumentName, $io->ask(sprintf('%s (from constructor): ', $argumentName)));
        }

        $methodInput = [];
        foreach ($arguments['referencedMethodArguments'] as $argumentName) {
            $methodInput[$argumentName] = $userInput[$argumentName] ?? $this->parseInputValue($argumentName, $io->ask(sprintf('%s: ', $argumentName)));
        }

        $value = $this->parameterEvaluator->evaluate(
            new AppDynamicParameterEvaluationEntry($selectedGroup, $selectedParameter, $methodInput, $constructorInput)
        );

        $io->block(sprintf('%s.%s: %s', $selectedGroup, $selectedParameter, $value));
        $io->block(sprintf('var_export: %s', var_export($value, true)));

        return Command::SUCCESS;
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
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
