<?php

declare(strict_types=1);

namespace App\Trait;

use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

trait DispatchCommandTrait
{
    use HandleTrait;

    private ?MessageBusInterface $commandBus;

    /**
     * @throws Throwable
     */
    protected function dispatchCommand(object $command): mixed
    {
        try {
            return $this->handle($command);
        } catch (HandlerFailedException $e) {
            while ($e instanceof HandlerFailedException) {
                $e = $e->getPrevious();
            }

            throw $e;
        }
    }
}
