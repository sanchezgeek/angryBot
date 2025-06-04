<?php

namespace App\Command\Account;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Command\AbstractCommand;
use App\Domain\Coin\Coin;
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

#[AsCommand(name: 'balance:info')]
class BalanceInfoCommand extends AbstractCommand
{
    private const string COIN_OPTION = 'coin';
    private const string TRANSFER_DIRECTION_OPTION = 'transferDirection';
    private const string TRANSFER_AMOUNT_OPTION = 'transferAmount';

    private const string DEFAULT_COIN = Coin::USDT->value;

    protected function configure(): void
    {
        $this
            ->addOption(self::COIN_OPTION, null, InputOption::VALUE_REQUIRED, 'Coin', self::DEFAULT_COIN)
            ->addOption(self::TRANSFER_AMOUNT_OPTION, 'a', InputOption::VALUE_REQUIRED, 'Transfer amount')
            ->addOption(self::TRANSFER_DIRECTION_OPTION, 't', InputOption::VALUE_REQUIRED,
                'Transfer direction (`sc` === SPOT → CONTRACT, `cs` === CONTRACT → SPOT, `fs` === FUNDING → SPOT, `sf` === SPOT → FUNDING, )'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $coin = Coin::from($this->paramFetcher->getStringOption(self::COIN_OPTION));

        if ($transferAmount = $this->paramFetcher->floatOption(self::TRANSFER_AMOUNT_OPTION)) {
            $to = $this->paramFetcher->getStringOption(self::TRANSFER_DIRECTION_OPTION);
            if (!in_array($to, ['sc', 'cs', 'fs', 'sf'])) {
                throw new InvalidArgumentException('`c` (contract) or `s`(spot) transferTo option values allowed.');
            }

            if ($to === 'sc') {
                $this->exchangeAccountService->interTransferFromSpotToContract($coin, $transferAmount);
            } elseif ($to === 'cs') {
                $this->exchangeAccountService->interTransferFromContractToSpot($coin, $transferAmount);
            } else {
                throw new InvalidArgumentException(sprintf('Unknown direction ("%s")', $to));
            }

            return Command::SUCCESS;
        }

        try {
            $spotWalletBalance = $this->exchangeAccountService->getSpotWalletBalance($coin);
            $this->io->note($spotWalletBalance);
            $contractWalletBalance = $this->exchangeAccountService->getContractWalletBalance($coin);
            $this->io->note($contractWalletBalance);
//             var_dump($spotWalletBalance->availableBalance, $contractWalletBalance->availableBalance);die;
        } catch (Exception $e) {
            if (!str_contains($e->getMessage(), 'coin data not found')) {
                throw $e;
            }
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
