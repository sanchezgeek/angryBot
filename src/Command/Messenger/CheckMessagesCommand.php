<?php

namespace App\Command\Messenger;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

#[AsCommand(name: 'messenger:check')]
class CheckMessagesCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->entityManager->getConnection();

        $result = $connection->executeQuery('SELECT count(*) from messenger_messages')->fetch();

        $output->writeln(sprintf('count: %d', $result['count']));

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
