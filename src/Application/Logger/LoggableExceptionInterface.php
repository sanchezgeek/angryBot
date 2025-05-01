<?php

declare(strict_types=1);

namespace App\Application\Logger;

use Throwable;

interface LoggableExceptionInterface extends Throwable
{
    public function data(): array;
}
