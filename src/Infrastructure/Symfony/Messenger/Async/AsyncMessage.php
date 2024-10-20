<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Async;

final class AsyncMessage
{
    private function __construct(public object $message)
    {
    }

    public static function for(object $message): self
    {
        return new self($message);
    }
}
