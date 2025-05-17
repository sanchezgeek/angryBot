<?php

declare(strict_types=1);

namespace App\Service\Infrastructure\Job\CheckMessengerMessages;

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

        $result = $connection->executeQuery('SELECT count(*) from messenger_messages');
        if ($result->rowCount() > self::LIMIT) {
            $this->appErrorLogger->error(sprintf('Found %d messages in messenger_messages.', $result->rowCount()));
            $connection->executeQuery('DELETE FROM messenger_messages WHERE 1=1');
        }
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $appErrorLogger,
    ) {
    }
}
