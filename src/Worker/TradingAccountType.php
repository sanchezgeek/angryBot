<?php

declare(strict_types=1);

namespace App\Worker;

enum TradingAccountType: string
{
    case CLASSIC = 'classic';
    case UTA = 'UTA';

    public function isUTA(): bool
    {
        return $this === self::UTA;
    }
}
