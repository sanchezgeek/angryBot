<?php

declare(strict_types=1);

namespace App\Helper;

use DateInterval;
use DateTimeImmutable;

final class DateTimeHelper
{
    public static function dateIntervalToSeconds(DateInterval $dateInterval): int
    {
        $reference = new DateTimeImmutable();
        $endTime = $reference->add($dateInterval);

        return abs($endTime->getTimestamp() - $reference->getTimestamp());
    }
}
