<?php

namespace App\Command\Account;

use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\V5\Request\Asset\History\GetHistoryRequest;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use DateTimeImmutable;
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

        $data = array_map(static fn(
            array $item
        ) => [
            'cashBalance' => $item['cashBalance'],
            'type' => $item['type'],
            'symbol' => $item['symbol'],
            'funding' => $item['funding'],
            'change' => $item['change'],
            'datetime' => new DateTimeImmutable()->setTimestamp($item['transactionTime'] / 1000)->format('d-m-Y H:i:s'),
        ], $data['list']);

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
