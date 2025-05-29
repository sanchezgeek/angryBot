<?php

namespace App\Command\Account\ApiKey;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Command\AbstractCommand;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Worker\AppContext;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'acc:api-key:refresh')]
class RefreshApiKeyCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('apikey', InputArgument::OPTIONAL, '[Sub] ApiKey')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apiKey = $this->paramFetcher->getStringArgument('apikey');
        if ($apiKey) {
            if (!AppContext::isMasterAccount()) {
                throw new InvalidArgumentException('Cannot update sub accounts api keys from non-master account.');
            }
            $this->exchangeAccountService->refreshApiKey($apiKey);
        } else {
            $this->exchangeAccountService->refreshApiKey();
        }

        return Command::SUCCESS;
    }

    /**
     * @param ByBitExchangeAccountService $exchangeAccountService
     * @param string|null $name
     */
    public function __construct(
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
