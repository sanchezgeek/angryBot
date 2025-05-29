<?php

namespace App\Command\Account\ApiKey;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Command\AbstractCommand;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'acc:api-key:info')]
class ApiKeyInfoCommand extends AbstractCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $info = $this->exchangeAccountService->getApiKeyInfo();

        OutputHelper::print($info);

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
