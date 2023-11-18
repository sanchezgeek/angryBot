<?php

namespace App\Command\Account;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Command\Mixin\PositionAwareCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'acc:info')]
class AccInfoCommand extends Command
{
    use PositionAwareCommand;

    private const TRANSFER_AMOUNT_OPTION = 'transferAmount';

    protected function configure(): void
    {
        $this
            ->addOption(self::TRANSFER_AMOUNT_OPTION, 't', InputOption::VALUE_REQUIRED, 'Transfer amount')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); $this->withInput($input);

        $symbol = Symbol::BTCUSDT;
        $coin = $symbol->associatedCoin();

        $spotWalletBalance = $this->exchangeAccountService->getSpotWalletBalance($coin);
        $contractWalletBalance = $this->exchangeAccountService->getContractWalletBalance($coin);

        var_dump(
            $spotWalletBalance->availableBalance,
            $contractWalletBalance->availableBalance
        );

        $transferAmount = $this->paramFetcher->floatOption(self::TRANSFER_AMOUNT_OPTION);

        if ($transferAmount) {
            $this->exchangeAccountService->interTransferFromSpotToContract($coin, $transferAmount);
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
