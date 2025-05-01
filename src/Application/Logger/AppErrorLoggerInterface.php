<?php

declare(strict_types=1);

namespace App\Application\Logger;

use Throwable;

interface AppErrorLoggerInterface
{
    public function error(string $message, array $data = []): void;
    public function exception(Throwable $e): void;
}
