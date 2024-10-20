<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Async\Debug;

use DateTimeInterface;

trait MessageWithDispatchingTimeTrait
{
    private ?DateTimeInterface $dispatchedDatetime = null;

    public function setDispatchedDateTime(DateTimeInterface $dateTime): void
    {
        $this->dispatchedDatetime = $dateTime;
    }

    public function getDispatchedDateTime(): ?DateTimeInterface
    {
        return $this->dispatchedDatetime;
    }
}
