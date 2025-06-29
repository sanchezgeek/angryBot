<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Messenger;

use App\Tests\Mixin\Console\RunCommandTrait;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

trait MessageConsumerTrait
{
    public const string ASYNC_CRITICAL_QUEUE = 'async_critical';
    public const string ASYNC_HIGH_QUEUE = 'async_high';

    use RunCommandTrait;

    /**
     * @before
     */
    protected function cleanTransport(string $name = 'test'): TransportInterface
    {
        $transport = $this->getTransport($name);

        do {
            $hasEnvelopes = false;
            $envelopes = $transport->get();

            foreach ($envelopes as $envelope) {
                $transport->ack($envelope);
                $hasEnvelopes = true;
            }
        } while ($hasEnvelopes);

        return $transport;
    }

    protected function runMessageConsume(
        object $message,
        string $queue = 'test',
        ?KernelInterface $kernel = null,
    ): ApplicationTester {
        $transport = $this->getTransport($queue);
        $transport->send(new Envelope($message));

        return $this->runCommand([
            'command' => 'messenger:consume',
            'receivers' => [$queue],
            '--limit' => '1',
            '--time-limit' => '1',
            '--sleep' => '0.01',
        ], [], $kernel);
    }

    private function getTransport(string $name): TransportInterface
    {
        return static::getContainer()->get('messenger.transport.' . $name);
    }

    /**
     * @before
     */
    protected function cleanDoctrineTransport(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        try {
            $entityManager->getConnection()->executeQuery('DELETE FROM messenger_messages WHERE 1=1');
        } catch (Exception\TableNotFoundException $e) {
        }
    }

    protected function assertMessagesWasDispatched(
        string $queue,
        array $expectedMessages,
    ): void {
        /**
         * Using Doctrine transport
         * @var ListableReceiverInterface $transport
         */
        $transport = $this->getTransport($queue);

        $messages = [];
        foreach ($transport->all() as $envelope) {
            $messages[] = $envelope->getMessage();
        }

        foreach ($expectedMessages as $expectedMessage) {
            self::assertTrue(in_array($expectedMessage, $messages));

            $key = array_search($expectedMessage, $messages);
            unset($messages[$key]);
        }
    }
}
