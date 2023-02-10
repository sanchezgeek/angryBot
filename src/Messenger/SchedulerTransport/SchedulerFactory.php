<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use App\Bot\Application\Message\CheckPositionJob;
use App\Bot\Application\Message\FindPositionBuyOrdersToAdd;
use App\Bot\Application\Message\FindPositionStopsToAdd;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use Exception;

/**
 * @codeCoverageIgnore
 */
final class SchedulerFactory
{
    private const VERY_FAST = 2;
    private const FAST = 3;
    private const MEDIUM = 4;
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
        $shortStopSpeed = self::FAST;
        $shortBuySpeed = self::MEDIUM;

        $longStopSpeed = self::FAST;
        $longBuySpeed = self::VERY_FAST;

        $jobSchedules = [
            // Простановка SL ШОРТ-позиции
            PeriodicalJob::infinite('2020-01-01T00:00:01Z', \sprintf('PT%sS', $shortStopSpeed), new FindPositionStopsToAdd(Symbol::BTCUSDT, Side::Sell)),

            // Покупка в ШОРТ-позицию
            PeriodicalJob::infinite('2020-01-01T00:00:02Z', \sprintf('PT%sS', $shortBuySpeed), new FindPositionBuyOrdersToAdd(Symbol::BTCUSDT, Side::Sell)),

            // LONG-позиции | STOP
            PeriodicalJob::infinite('2020-01-01T00:00:03Z',\sprintf('PT%sS', $longStopSpeed), new FindPositionStopsToAdd(Symbol::BTCUSDT, Side::Buy)),

            // LONG-позиция | BUY
            PeriodicalJob::infinite('2020-01-01T00:00:04Z', \sprintf('PT%sS', $longBuySpeed), new FindPositionBuyOrdersToAdd(Symbol::BTCUSDT, Side::Buy)),
        ];

        return new Scheduler($clock, $jobSchedules);
    }

    private static function infinite(string $interval, object $job): PeriodicalJob
    {
        return PeriodicalJob::infinite('2020-01-01T00:00:00Z', $interval, $job);
    }
}


//            PeriodicalJob::infinite('2020-01-01T00:00:00Z', 'PT5S', new CheckPositionJob(Symbol::BTCUSDT, Side::Sell)),
