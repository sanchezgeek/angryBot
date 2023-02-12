<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use App\Bot\Application\Message\FindPositionBuyOrdersToAdd;
use App\Bot\Application\Message\FindPositionStopsToAdd;
use App\Bot\Application\Message\Job\FixupOrdersDoubling;
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
        $shortBuySpeed = self::MEDIUM;

        $longStopSpeed = self::VERY_FAST;
        $longBuySpeed = self::FAST;

//        $shortStopSpeed = self::FAST;
//        $shortBuySpeed = self::MEDIUM;
//
//        $longStopSpeed = self::FAST;
//        $longBuySpeed = self::MEDIUM;

        $jobSchedules = [
            // SHORT-позиция | SL
            PeriodicalJob::infinite('2020-01-01T00:00:01Z', \sprintf('PT%sS', $shortStopSpeed), new FindPositionStopsToAdd(Symbol::BTCUSDT, Side::Sell)),

            // SHORT-позиция | BUY
            PeriodicalJob::infinite('2020-01-01T00:00:02Z', \sprintf('PT%sS', $shortBuySpeed), new FindPositionBuyOrdersToAdd(Symbol::BTCUSDT, Side::Sell)),

            // LONG-позиция | SL
            PeriodicalJob::infinite('2020-01-01T00:00:03Z', \sprintf('PT%sS', $longStopSpeed), new FindPositionStopsToAdd(Symbol::BTCUSDT, Side::Buy)),

            // LONG-позиция | BUY
            PeriodicalJob::infinite('2020-01-01T00:00:04Z', \sprintf('PT%sS', $longBuySpeed), new FindPositionBuyOrdersToAdd(Symbol::BTCUSDT, Side::Buy)),

            // Utils
            PeriodicalJob::infinite('2020-01-01T00:01:05Z', \sprintf('PT%s', $cleanupPeriod = '45S'), new FixupOrdersDoubling(OrderType::Stop, Side::Sell, 1, 4, true)), // Cleanup SHORT StopLoses (+add removed volume to group)
            PeriodicalJob::infinite('2020-01-01T00:02:06Z', \sprintf('PT%s', $cleanupPeriod), new FixupOrdersDoubling(OrderType::Add, Side::Sell, 1, 1)), // Cleanup SHORT purchases
            PeriodicalJob::infinite('2020-01-01T00:03:07Z', \sprintf('PT%s', $cleanupPeriod), new FixupOrdersDoubling(OrderType::Stop, Side::Buy, 1, 2, true)), // Cleanup LONG StopLoses (+add removed volume to group)
            PeriodicalJob::infinite('2020-01-01T00:04:08Z', \sprintf('PT%s', $cleanupPeriod), new FixupOrdersDoubling(OrderType::Add, Side::Buy, 1, 2)), // Cleanup LONG purchases
        ];

        return new Scheduler($clock, $jobSchedules);
    }
}
