<?php

declare(strict_types=1);

namespace App\Application\Messenger\Infrastructure\CheckMessengerMessages;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CheckMessengerMessagesHandler
{
    const LIMIT = 50;

    public function __invoke(CheckMessengerMessages $message): void
    {
        $connection = $this->entityManager->getConnection();

        $result = $connection->executeQuery('SELECT count(*) from messenger_messages')->fetch();
        $count = (int) $result['count'];
        if ($count > self::LIMIT) {
            $this->appErrorLogger->error(sprintf('Found %d messages in messenger_messages.', $count));
            $connection->executeQuery('DELETE FROM messenger_messages WHERE 1=1')->fetch();
        }
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $appErrorLogger,
    ) {
    }
}
