<?php

namespace App\Command\Account;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Domain\Order\Service\OrderCostHelper;
use Exception;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function in_array;
use function sprintf;
use function str_contains;

#[AsCommand(name: 'acc:info')]
class AccInfoCommand extends AbstractCommand
{
    use SymbolAwareCommand;

    private const TRANSFER_TO_OPTION = 'transferTo';
    private const TRANSFER_AMOUNT_OPTION = 'transferAmount';

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs()
            ->addOption(self::TRANSFER_AMOUNT_OPTION, 'a', InputOption::VALUE_REQUIRED, 'Transfer amount')
            ->addOption(self::TRANSFER_TO_OPTION, 't', InputOption::VALUE_REQUIRED, 'Transfer to (`c` - to CONTRACT, `s` - to SPOT)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $coin = $this->getSymbol()->associatedCoin();

        try {
            $spotWalletBalance = $this->exchangeAccountService->getSpotWalletBalance($coin);
            $contractWalletBalance = $this->exchangeAccountService->getContractWalletBalance($coin);
            $this->io->note(sprintf('spot: %.3f available / %.3f total', $spotWalletBalance->availableBalance, $spotWalletBalance->totalBalance));
            $this->io->note(sprintf('contract: %.3f available / %.3f total', $contractWalletBalance->availableBalance, $contractWalletBalance->totalBalance));
        } catch (Exception $e) {
            if (!str_contains($e->getMessage(), 'coin data not found')) {
                throw $e;
            }
        }

        if ($transferAmount = $this->paramFetcher->floatOption(self::TRANSFER_AMOUNT_OPTION)) {
            $to = $this->paramFetcher->getStringOption(self::TRANSFER_TO_OPTION);
            if (!in_array($to, ['c', 's'])) {
                throw new InvalidArgumentException('`c` (contract) or `s`(spot) transferTo option values allowed.');
            }

            if ($to === 'c') {
                $this->exchangeAccountService->interTransferFromSpotToContract($coin, $transferAmount);
            } else {
                $this->exchangeAccountService->interTransferFromContractToSpot($coin, $transferAmount);
            }
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly OrderCostHelper $orderCostHelper,
        private readonly MarketServiceInterface $marketService,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
