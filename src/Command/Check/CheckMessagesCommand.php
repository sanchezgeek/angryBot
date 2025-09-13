<?php

namespace App\Command\Check;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(name: 'c:queues')]
class CheckMessagesCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $connection = $this->entityManager->getConnection();

        $result = $connection->executeQuery('SELECT * from messenger_messages')->fetchAllAssociative();
        foreach ($result as $item) {
            var_dump($item['queue_name'], $item['body']);
        }

        $result = $connection->executeQuery('SELECT count(*) from messenger_messages')->fetchAssociative();

        $count = (int) $result['count'];
        $output->writeln(sprintf('count: %d', $count));

        if ($count && $io->ask('remove?')) {
            $connection->executeQuery('DELETE FROM messenger_messages WHERE 1=1');
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
