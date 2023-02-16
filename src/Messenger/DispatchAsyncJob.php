<?php

declare(strict_types=1);

namespace App\Messenger;

final class DispatchAsyncJob
{
    public function __construct(public object $message)
    {
    }

    public static function message(object $message): self
    {
        return new self($message);
    }
}
