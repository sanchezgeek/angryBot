<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Messenger;

use App\Tests\Mixin\Console\RunCommandTrait;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

trait MessageConsumerTrait
{
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
}
