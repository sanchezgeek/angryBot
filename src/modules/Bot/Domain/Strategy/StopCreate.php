<?php

declare(strict_types=1);

namespace App\Bot\Domain\Strategy;

enum StopCreate: string
{
    case DEFAULT = 'default';

    case UNDER_POSITION = 'under_position';
    case ONLY_BIG_SL_UNDER_POSITION = 'only_big_sl_under_position';

    case AFTER_FIRST_STOP_UNDER_POSITION = 'after_first_stop_under_position';
    case ONLY_BIG_SL_AFTER_FIRST_STOP_UNDER_POSITION = 'only_big_sl_after_first_stop_under_position';

    case AFTER_FIRST_POSITION_STOP = 'after_first_position_stop';

    public const BIG_SL_VOLUME_STARTS_FROM = 0.006;

    private const REGULAR_ORDER_STOP_DISTANCE = 131;
    private const ADDITION_ORDER_STOP_DISTANCE = 113;

//    private const REGULAR_ORDER_STOP_DISTANCE = 791;
//    private const ADDITION_ORDER_STOP_DISTANCE = 753;

//    private const HEDGE_POSITION_REGULAR__ORDER_STOP_DISTANCE = 45;
//    private const HEDGE_POSITION_ADDITION_ORDER_STOP_DISTANCE = 70;

    public static function getDefaultStrategyStopOrderDistance(float $volume): float
    {
        return $volume >= 0.005 ? self::REGULAR_ORDER_STOP_DISTANCE : self::ADDITION_ORDER_STOP_DISTANCE;
    }
}
