<?php

namespace App\Screener\UI\Symfony\Command;

use App\Bot\Domain\Ticker;
use App\Command\AbstractCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\SymbolDependentCommand;
use App\Domain\Coin\Coin;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Settings\Application\Service\AppSettingsService;
use App\TechnicalAnalysis\Application\Service\TAToolsProvider;
use App\Trading\Application\UseCase\OpenPosition\OpenPositionHandler;
use App\Trading\Application\UseCase\OpenPosition\OrdersGrids\OpenPositionBuyGridsDefinitions;
use App\Trading\Application\UseCase\OpenPosition\OrdersGrids\OpenPositionStopsGridsDefinitions;
use App\Trading\Domain\Symbol\Helper\SymbolHelper;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @todo compare with ATR
 */
#[AsCommand(name: 'ta:test')]
class TaTestCommand extends AbstractCommand implements SymbolDependentCommand
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
        $symbols = $this->getSymbols();
        $tickers = $this->exchangeService->getAllTickers(Coin::USDT, static fn (string $symbolName) => in_array($symbolName, SymbolHelper::symbolsToRawValues(...$symbols)));

        foreach ($tickers as $ticker) {
            $ta = $this->taFactory->create($ticker->symbol, TimeFrame::D1);

            $averagePriceChange = $ta->averagePriceChangePrev(3)->averagePriceChange;
            $absolute = $averagePriceChange->absoluteChange;

            // x1.5 - significant one day
            // /3.5 - /3 - first stops grid ?

            // stops grid:
            //  /3.2 - /3 aggressive
            //  /4 - /3.5 conservative

            // подобрать для битка 5000 as safe? посмотреть на каком интервале и qnt сегодня было 5000

            $res4 = $absolute / 4;
            $res3 = $absolute / 3;

            $this->io->writeln(
                sprintf(
                    "%s: %s (%s of current price)\n          % 10s=%s (%s of current price)\n% 10s=%s (%s of current price)\n",
                    $ticker->symbol->name(),
                    $averagePriceChange,
                    Percent::fromPart($absolute / $ticker->indexPrice->value()),
                    '/4',
                    $res4,
                    Percent::fromPart($res4 / $ticker->indexPrice->value()),
                    '/3',
                    $res3,
                    Percent::fromPart($res3 / $ticker->indexPrice->value()),
                )
            );
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly TAToolsProvider $taFactory,

        private readonly AppSettingsService $settingsService,
        private readonly OpenPositionHandler $openPositionHandler,
        private readonly OpenPositionBuyGridsDefinitions $buyOrdersGridDefinitionFinder,
        private readonly OpenPositionStopsGridsDefinitions $stopsGridDefinitionFinder,
        private readonly ByBitLinearExchangeService $exchangeService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
