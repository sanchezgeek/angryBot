<?php

namespace App\Settings\UI\Symfony\Command\DynamicParameters;

use App\Bot\Domain\Ticker;
use App\Command\AbstractCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\SymbolDependentCommand;
use App\Domain\Coin\Coin;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Settings\Application\DynamicParameters\AppDynamicParametersLocator;
use App\Settings\Application\DynamicParameters\DefaultValues\Provider\Exception\DefaultPositionCannotBeProvided;
use App\Settings\Application\DynamicParameters\Evaluation\AppDynamicParameterEvaluationEntry;
use App\Settings\Application\DynamicParameters\Evaluation\AppDynamicParameterEvaluator;
use App\Settings\Application\Service\AppSettingsService;
use App\TechnicalAnalysis\Application\Service\TAToolsProvider;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\UseCase\OpenPosition\OpenPositionHandler;
use App\Trading\Application\UseCase\OpenPosition\OrdersGrids\OpenPositionBuyGridsDefinitions;
use App\Trading\Application\UseCase\OpenPosition\OrdersGrids\OpenPositionStopsGridsDefinitions;
use App\Trading\Domain\Symbol\Helper\SymbolHelper;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @todo or `info`
 */
#[AsCommand(name: 'parameters:all')]
class ShowAllParametersCommand extends AbstractCommand implements SymbolDependentCommand
{
    use SymbolAwareCommand;

    private SymbolInterface $symbol;
    private Ticker $ticker;

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs()
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->symbolIsSpecified()) {
            $symbols = $this->getSymbols();
        } else {
            $symbols = $this->positionService->getOpenedPositionsSymbols();
        }

        $tickers = $this->exchangeService->getAllTickers(Coin::USDT, static fn (string $symbolName) => in_array($symbolName, SymbolHelper::symbolsToRawValues(...$symbols)));

        ['constructorsArguments' => $constructorsArguments, 'methodsArguments' => $methodsArguments] = $this->parameterEvaluator->getArgumentsToEvaluateAllParameters();


        $commonUserInput = [];

        foreach ($tickers as $ticker) {
            $symbol = $ticker->symbol;
            $sides = [Side::Buy, Side::Sell];

            foreach ($sides as $side) {
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
                        } catch (DefaultPositionCannotBeProvided) {
                            continue;
                        }

                        $io->writeln(sprintf('%10s %5s %60s: %s', $symbol->name(), $side->title(), $groupName . '.' . $parameterName, $value));
                    }
                }
                echo "\n";
            }

            echo "\n\n\n";
        }

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
        private readonly TAToolsProvider $taFactory,

        private readonly AppSettingsService $settingsService,
        private readonly OpenPositionHandler $openPositionHandler,
        private readonly OpenPositionBuyGridsDefinitions $buyOrdersGridDefinitionFinder,
        private readonly OpenPositionStopsGridsDefinitions $stopsGridDefinitionFinder,
        private readonly ByBitLinearExchangeService $exchangeService,
        private readonly ByBitLinearPositionService $positionService,
        private readonly TradingParametersProviderInterface $tradingParameters,
        private readonly AppDynamicParameterEvaluator $parameterEvaluator,
        private readonly AppDynamicParametersLocator $parametersLocator,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
