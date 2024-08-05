<?php

namespace App\Alarm\Application\Messenger\Job;

use App\Bot\Domain\ValueObject\Symbol;

readonly class CheckAlarm
{
    public function __construct(public Symbol $symbol)
    {
    }
}
