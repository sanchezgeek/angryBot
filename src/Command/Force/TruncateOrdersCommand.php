<?php

namespace App\Command\Force;

use App\Command\AbstractCommand;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'o:truncate')]
class TruncateOrdersCommand extends AbstractCommand
{
    private const MODE_ALL = 'all';
    private const MODE_BUY_ORDERS = 'buy';
    private const MODE_STOPS = 'sl';

    protected function configure(): void
    {
        $this
            ->addOption(self::MODE_ALL, null, InputOption::VALUE_NEGATABLE)
            ->addOption(self::MODE_BUY_ORDERS, null, InputOption::VALUE_NEGATABLE)
            ->addOption(self::MODE_STOPS, null, InputOption::VALUE_NEGATABLE)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = match(true) {
            $this->paramFetcher->getBoolOption(self::MODE_ALL) => 'all',
            $this->paramFetcher->getBoolOption(self::MODE_BUY_ORDERS) => 'buy',
            $this->paramFetcher->getBoolOption(self::MODE_STOPS) => 'stops',
            default => throw new InvalidArgumentException('One of modes must be selected')
        };

        $connection = $this->entityManager->getConnection();
        if ($mode === 'all') {
            $connection->exec('DELETE FROM buy_order WHERE 1=1');
            $connection->executeQuery('SELECT setval(\'buy_order_id_seq\', 1, false);');
            $connection->exec('DELETE FROM stop WHERE 1=1');
            $connection->executeQuery('SELECT setval(\'stop_id_seq\', 1, false);');
        } elseif ($mode === 'buy') {
            $connection->exec('DELETE FROM buy_order WHERE 1=1');
            $connection->executeQuery('SELECT setval(\'buy_order_id_seq\', 1, false);');
        } else {
            $connection->exec('DELETE FROM stop WHERE 1=1');
            $connection->executeQuery('SELECT setval(\'stop_id_seq\', 1, false);');
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
