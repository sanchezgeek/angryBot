<?php

declare(strict_types=1);

namespace App\Domain\Trading\Helper;

use App\Domain\Trading\Enum\TimeFrame;
use DateInterval;

final class TimeframeHelper
{
    private static array $intervalsCache = [];

    public static function timeframeToDateInterval(TimeFrame $timeFrame): DateInterval
    {
        $rawValue = $timeFrame->value;

        if (!isset(self::$intervalsCache[$rawValue])) {
            self::$intervalsCache[$rawValue] = DateInterval::createFromDateString(TimeFrame::DATE_INTERVALS[$rawValue]);
        }

        return self::$intervalsCache[$rawValue];
    }
}
