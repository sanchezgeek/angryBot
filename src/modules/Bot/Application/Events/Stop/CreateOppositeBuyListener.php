<?php

declare(strict_types=1);

namespace App\Bot\Application\Events\Stop;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class CreateOppositeBuyListener
{
    public function __invoke(StopPushedToExchange $event): void
    {

    }
}
