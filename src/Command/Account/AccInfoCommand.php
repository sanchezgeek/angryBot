<?php

namespace App\Command\Account;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Command\Mixin\PositionAwareCommand;
use App\Domain\Order\Service\OrderCostHelper;
use App\Domain\Price\Helper\PriceHelper;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function in_array;

#[AsCommand(name: 'acc:info')]
class AccInfoCommand extends Command
{
    use PositionAwareCommand;

    private const TRANSFER_TO_OPTION = 'transferTo';
    private const TRANSFER_AMOUNT_OPTION = 'transferAmount';

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addOption(self::TRANSFER_AMOUNT_OPTION, 'a', InputOption::VALUE_REQUIRED, 'Transfer amount')
            ->addOption(self::TRANSFER_TO_OPTION, 't', InputOption::VALUE_REQUIRED, 'Transfer to (`c` - to CONTRACT, `s` - to SPOT)')
            ->addOption('funding', null, InputOption::VALUE_NEGATABLE, 'Make funding fee transfer')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); $this->withInput($input);

        $symbol = Symbol::BTCUSDT;
        $coin = $symbol->associatedCoin();

        $spotWalletBalance = $this->exchangeAccountService->getSpotWalletBalance($coin);
        $contractWalletBalance = $this->exchangeAccountService->getContractWalletBalance($coin);

        if ($this->paramFetcher->getBoolOption('funding')) {
            $prevPeriodRate = PriceHelper::round($this->marketService->getPreviousPeriodFundingRate($symbol), 7);
            var_dump($prevPeriodRate);

            $position = $this->getPosition();
            $fee = PriceHelper::round($position->value * $prevPeriodRate, 4);

            if ($fee > 0) {
                $this->exchangeAccountService->interTransferFromContractToSpot($coin, $fee);
            } else {
                $this->exchangeAccountService->interTransferFromSpotToContract($coin, $fee);
            }

            $io->success('Success!!!');
            return Command::SUCCESS;
        }

        var_dump(
            $spotWalletBalance->availableBalance,
            $contractWalletBalance->availableBalance
        );

        $transferAmount = $this->paramFetcher->floatOption(self::TRANSFER_AMOUNT_OPTION);

        if ($transferAmount) {
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
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
