<?php

namespace App\Command\Account\ApiKey;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Command\AbstractCommand;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'acc:api-key:refresh')]
class RefreshApiKeyCommand extends AbstractCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->exchangeAccountService->refreshApiKey();

        return Command::SUCCESS;
    }

    /**
     * @param ByBitExchangeAccountService $exchangeAccountService
     * @param string|null $name
     */
    public function __construct(
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        string $name = null,
    ) {

        parent::__construct($name);
    }
}
