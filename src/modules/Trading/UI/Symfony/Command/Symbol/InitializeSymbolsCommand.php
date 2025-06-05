<?php

namespace App\Trading\UI\Symfony\Command\Symbol;

use App\Command\AbstractCommand;
use App\Domain\Coin\Coin;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\QuoteCoinNotEqualsSpecifiedOneException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\UnsupportedAssetCategoryException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\InitializeSymbolsEntry;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\InitializeSymbolsHandler;
use App\Trading\Domain\Symbol\Repository\SymbolRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'symbol:initialize')]
class InitializeSymbolsCommand extends AbstractCommand
{
    private const string SYMBOL_NAME = 'name';
    private const string COIN_NAME = 'coin';

    private Coin $coin;

    protected function configure(): void
    {
        $this
            ->addOption(self::SYMBOL_NAME, null, InputOption::VALUE_OPTIONAL, 'Symbol name (if null all symbols will be initialized)')
            ->addOption(self::COIN_NAME, null, InputOption::VALUE_OPTIONAL, 'Coin (required if no symbol specified)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settleCoin = $this->paramFetcher->getStringOption(self::COIN_NAME);
        $this->coin = Coin::from($settleCoin);

        $this->io->info(sprintf('Start fetching "%s" symbols from exchange', $settleCoin));

        if ($symbolName = $this->paramFetcher->getStringOption(self::SYMBOL_NAME, false)) {
            $this->initSymbol($symbolName);
        } else {
            foreach ($this->exchangeService->getAllAvailableSymbolsRaw($this->coin) as $symbolRaw) {
                $this->initSymbol($symbolRaw);
            }
        }

        return Command::SUCCESS;
    }

    private function initSymbol(string $name): void
    {
        if ($this->symbolRepository->findOneByName($name)) {
            return;
        }

        try {
            $this->initializeSymbolsHandler->handle(
                new InitializeSymbolsEntry($name, $this->coin)
            );
        } catch (UnsupportedAssetCategoryException|QuoteCoinNotEqualsSpecifiedOneException $e) {
            self::info(sprintf('Skip "%s": %s', $name, $e->getMessage()));
            return;
        }
    }

    private static function info(string $message): void
    {
        OutputHelper::print(sprintf('[%s] %s', OutputHelper::shortClassName(__CLASS__), $message));
    }

    public function __construct(
        private readonly SymbolRepository $symbolRepository,
        private readonly ByBitLinearExchangeService $exchangeService,
        private readonly InitializeSymbolsHandler $initializeSymbolsHandler,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
