<?php

declare(strict_types=1);

namespace App\Domain\Candle\Enum;

use DateInterval;

enum CandleIntervalEnum: string
{
    case m1 = '1m';
    case m5 = '5m';
    case m15 = '15m';
    case m30 = '30m';
    case h1 = '1h';
    case h2 = '2h';
    case h3 = '3h';
    case h4 = '4h';
    case h6 = '6h';
    case h12 = '12h';
    case D1 = '1D';
    case W1 = '1W';
    case M1 = '1M';

    private const array DATE_INTERVALS = [
        self::m1->value => '1 minute',
        self::m5->value => '5 minutes',
        self::m15->value => '15 minutes',
        self::m30->value => '30 minutes',
        self::h1->value => '1 hour',
        self::h2->value => '2 hours',
        self::h3->value => '3 hours',
        self::h4->value => '4 hours',
        self::h6->value => '6 hours',
        self::h12->value => '12 hours',
        self::D1->value => '1 day',
        self::W1->value => '1 week',
        self::M1->value => '1 month',
    ];

    public function toDateInterval(): DateInterval
    {
        // @todo | cache
        return DateInterval::createFromDateString(self::DATE_INTERVALS[$this->value]);
    }
}
