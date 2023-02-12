<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Strategy\Hedge;

enum HedgeOppositeStopCreate: string
{
    case DEFAULT_STOP_STRATEGY = 'default_stop_strategy';

    case AFTER_FIRST_POSITION_STOP = 'after_first_position_stop';
    case UNDER_POSITION = 'under_position';

    case ONLY_BIG_SL_UNDER_POSITION = 'only_big_sl_under_position';
    case ONLY_BIG_SL_AFTER_FIRST_POSITION_STOP = 'only_big_sl_after_first_position_stop';

    public const BIG_SL_VOLUME_STARTS_FROM = 0.006;
}
