<?php

namespace App\Trading\UI\Symfony\Command\Symbol;

use App\Command\AbstractCommand;
use App\Domain\Coin\Coin;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Trading\Domain\Symbol\Entity\Symbol;
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
    private array $availableContractTypes = [
        'LinearPerpetual',
    ];

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

        $symbolName = $this->paramFetcher->getStringOption(self::SYMBOL_NAME, false);

        if ($symbolName) {
            $this->initSymbol($symbolName);
        } else {
            $tickers = $this->exchangeService->getAllTickersRaw($this->coin);
            foreach ($tickers as $symbolRaw => $prices) {
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

        $info = $this->exchangeService->getInstrumentInfo($name);

        if ($info->quoteCoin !== $this->coin->value) {
            self::info(sprintf('Skip "%s": quoteCoin ("%s") !== specified coin ("%s")', $name, $info->quoteCoin, $this->coin->value));
            return;
        }

        if (!in_array($info->contractType, $this->availableContractTypes, true)) {
            self::info(sprintf('Skip "%s" (unknown category "%s"). Available categories: "%s"', $name, $info->contractType, implode('", "', $this->availableContractTypes)));
            return;
        }

        $category = match ($info->contractType) {
            'LinearPerpetual' => AssetCategory::linear,
        };

        $symbol = new Symbol(
            $name,
            Coin::from($info->quoteCoin),
            $category,
            $info->minOrderQty,
            $info->minOrderValue,
            $info->priceScale
        );

        $this->symbolRepository->save($symbol);
    }

    private static function info(string $message): void
    {
        OutputHelper::print(sprintf('[%s] %s', OutputHelper::shortClassName(__CLASS__), $message));
    }

    public function __construct(
        private readonly SymbolRepository $symbolRepository,
        private readonly ByBitLinearExchangeService $exchangeService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
