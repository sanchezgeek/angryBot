<?php

declare(strict_types=1);

namespace App\Application\Messenger;

use DateTimeInterface;

trait TimeStampedAsyncMessageTrait
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
