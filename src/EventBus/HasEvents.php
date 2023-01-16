<?php

namespace App\EventBus;

interface HasEvents
{
    public function releaseEvents(): array;
}
