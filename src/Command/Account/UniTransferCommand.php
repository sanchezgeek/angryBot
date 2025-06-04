<?php

namespace App\Command\Account;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Command\AbstractCommand;
use App\Domain\Coin\Coin;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

#[AsCommand(name: 'asset:uni-transfer')]
class UniTransferCommand extends AbstractCommand
{
    private const string COIN_OPTION = 'coin';
    private const string TRANSFER_AMOUNT_OPTION = 'transferAmount';
    private const string FROM_ACCOUNT = 'from';
    private const string TO_ACCOUNT = 'to';

    private const string DEFAULT_COIN = Coin::USDT->value;

    private array $accountUids;

    protected function configure(): void
    {
        $this
            ->addOption(self::COIN_OPTION, null, InputOption::VALUE_REQUIRED, 'Coin', self::DEFAULT_COIN)
            ->addOption(self::TRANSFER_AMOUNT_OPTION, 'a', InputOption::VALUE_REQUIRED, 'Transfer amount')
            ->addOption(self::FROM_ACCOUNT, 'f', InputOption::VALUE_REQUIRED)
            ->addOption(self::TO_ACCOUNT, 't', InputOption::VALUE_REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $coin = Coin::from($this->paramFetcher->getStringOption(self::COIN_OPTION));

        $amount = $this->paramFetcher->requiredFloatOption(self::TRANSFER_AMOUNT_OPTION);

        $from = $this->parseAccountAndType($this->paramFetcher->getStringOption(self::FROM_ACCOUNT), 'from');
        $to = $this->parseAccountAndType($this->paramFetcher->getStringOption(self::TO_ACCOUNT), 'to');

        $this->exchangeAccountService->universalTransfer($coin, $amount, $from[0], $to[0], $from[1], $to[1]);

        return Command::SUCCESS;
    }

    private function parseAccountAndType(string $raw, string $name): array
    {
        $parts = explode('.', $raw);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException(sprintf('Invalid account [%s] definition provided: "%s"', $name, $raw));
        }

        $accName = $parts[0];
        $type = $parts[1];

        if (!($uid = $this->accountUids[$accName] ?? null)) {
            throw new InvalidArgumentException(sprintf('Cannot find account [%s] by provided name "%s"', $name, $accName));
        }

        $type = AccountType::tryFrom($type);

        return [$type, $uid];
    }

    /**
     * @param ByBitExchangeAccountService $exchangeAccountService
     */
    public function __construct(
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        string $accountUids,
        ?string $name = null,
    ) {
        $this->accountUids = json_decode($accountUids, true);

        parent::__construct($name);
    }
}
