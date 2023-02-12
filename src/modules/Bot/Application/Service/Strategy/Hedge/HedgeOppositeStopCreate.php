<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Strategy\Hedge;

enum HedgeOppositeStopCreate: string
{
    case AFTER_FIRST_POSITION_STOP = 'after_first_position_stop';
    case UNDER_POSITION = 'under_position';
    case DEFAULT_STOP_STRATEGY = 'default_stop_strategy';
}
