<?php

namespace App\Command\Account;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Command\AbstractCommand;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sub-acc:create-api-key')]
class CreateApiKeyCommand extends AbstractCommand
{
    private array $accountUids;

    protected function configure(): void
    {
        $this
            ->addArgument('accName', InputArgument::REQUIRED, 'Account name')
            ->addArgument('note', InputArgument::REQUIRED, 'Note')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $accName = $this->paramFetcher->getStringArgument('accName');
        $note = $this->paramFetcher->getStringArgument('note');

        if (!($uid = $this->accountUids[$accName] ?? null)) {
            throw new InvalidArgumentException(sprintf('Cannot find account by provided name "%s"', $accName));
        }

        $this->exchangeAccountService->createSubAccountApiKey($uid, $note);

        return Command::SUCCESS;
    }

    /**
     * @param ByBitExchangeAccountService $exchangeAccountService
     * @param string|null $name
     */
    public function __construct(
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        string $accountUids,
        string $name = null,
    ) {
        $this->accountUids = json_decode($accountUids, true);

        parent::__construct($name);
    }
}
