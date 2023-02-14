<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushRelevantBuyOrders;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushRelevantStopOrders;
use App\Bot\Application\Messenger\Job\Utils\FixupOrdersDoubling;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use Exception;

/**
 * @codeCoverageIgnore
 */
final class SchedulerFactory
{
    private const VERY_FAST = 1;
    private const FAST = 2;
    private const MEDIUM = 3;
    private const SLOW = 5;
    private const VERY_SLOW = 10;

    private function __construct()
    {
    }

    /**
     * @throws Exception if invalid date or period
     */
    public static function createScheduler(ClockInterface $clock): Scheduler
    {
        $shortStopSpeed = self::VERY_FAST;
        $shortBuySpeed = self::FAST;

        $longStopSpeed = self::FAST;
        $longBuySpeed = self::FAST;

//        $shortStopSpeed = self::FAST;
//        $shortBuySpeed = self::MEDIUM;
//
//        $longStopSpeed = self::FAST;
//        $longBuySpeed = self::MEDIUM;
        $cleanupPeriod = '45S';
        $jobSchedules = [
                                                /**** Push relevant orders to Exchange *****/

            // SHORT-position | SL
            PeriodicalJob::infinite('2020-01-01T00:00:01Z', \sprintf('PT%sS', $shortStopSpeed), new PushRelevantStopOrders(Symbol::BTCUSDT, Side::Sell)),

            // SHORT-position | BUY
            PeriodicalJob::infinite('2020-01-01T00:00:02Z', \sprintf('PT%sS', $shortBuySpeed), new PushRelevantBuyOrders(Symbol::BTCUSDT, Side::Sell)),

            // LONG-position | SL
            PeriodicalJob::infinite('2020-01-01T00:00:03Z', \sprintf('PT%sS', $longStopSpeed), new PushRelevantStopOrders(Symbol::BTCUSDT, Side::Buy)),

            // LONG-position | BUY
            PeriodicalJob::infinite('2020-01-01T00:00:04Z', \sprintf('PT%sS', $longBuySpeed), new PushRelevantBuyOrders(Symbol::BTCUSDT, Side::Buy)),



                                                            /**** Utils *****/

            // Cleanup SHORT-position | SL
            PeriodicalJob::infinite('2020-01-01T00:01:05Z', \sprintf('PT%s', $cleanupPeriod), new FixupOrdersDoubling(OrderType::Stop, Side::Sell, 1, 4, true)),

            // Cleanup SHORT-position | BUY
            PeriodicalJob::infinite('2020-01-01T00:01:06Z', \sprintf('PT%s', $cleanupPeriod), new FixupOrdersDoubling(OrderType::Add, Side::Sell, 1, 1)),

            // Cleanup LONG-position | SL
            PeriodicalJob::infinite('2020-01-01T00:01:07Z', \sprintf('PT%s', $cleanupPeriod), new FixupOrdersDoubling(OrderType::Stop, Side::Buy, 1, 3, true)),

            // Cleanup LONG-position | BUY
            PeriodicalJob::infinite('2020-01-01T00:01:08Z', \sprintf('PT%s', $cleanupPeriod), new FixupOrdersDoubling(OrderType::Add, Side::Buy, 1, 2, true)),
        ];

        return new Scheduler($clock, $jobSchedules);
    }
}
