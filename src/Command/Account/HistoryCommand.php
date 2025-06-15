<?php

namespace App\Command\Account;

use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\V5\Request\Asset\History\GetHistoryRequest;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'transactions:history')]
class HistoryCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;
    use ByBitApiCallHandler;

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $request = new GetHistoryRequest();

        $byBitApiCallResult = $this->sendRequest($request);
        $data = $byBitApiCallResult->data();
        var_dump($data);die;
    }

    public function __construct(
        ByBitApiClientInterface $apiClient,
        ?string $name = null,
    ) {
        $this->apiClient = $apiClient;

        parent::__construct($name);
    }
}
