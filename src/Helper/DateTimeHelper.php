<?php

declare(strict_types=1);

namespace App\Helper;

use DateInterval;
use DateTime;
use DateTimeImmutable;

final class DateTimeHelper
{
    public static function secondsInMinutes(int $minutes): int
    {
        return $minutes * 60;
    }

    public static function dateIntervalToSeconds(DateInterval $dateInterval): int
    {
        $reference = new DateTimeImmutable();
        $endTime = $reference->add($dateInterval);

        return abs($endTime->getTimestamp() - $reference->getTimestamp());
    }

    public static function dateIntervalToDays(DateInterval $dateInterval): float
    {
        $reference = new DateTimeImmutable();
        $endTime = $reference->add($dateInterval);

        return abs($endTime->getTimestamp() - $reference->getTimestamp()) / 86400;
    }

    public static function nextHour(): DateTime
    {
        $date = new DateTime();
        $date->setTime((int)$date->format('H') + 1, 0, 0);

        return $date;
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
