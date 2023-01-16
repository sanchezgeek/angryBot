<?php

declare(strict_types=1);

namespace App\Trait;

use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

trait DispatchCommandTrait
{
    private ?MessageBusInterface $commandBus;

    /**
     * @throws Throwable
     */
    private function dispatchCommand(object $command): void
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $e) {
            while ($e instanceof HandlerFailedException) {
                $e = $e->getPrevious();
            }

            throw $e;
        }
    }
}
