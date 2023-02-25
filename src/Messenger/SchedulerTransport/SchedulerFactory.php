<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Messenger\Job\Cache\UpdateTicker;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushRelevantBuyOrders;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushRelevantStopOrders;
use App\Bot\Application\Messenger\Job\Utils\FixupOrdersDoubling;
use App\Bot\Application\Messenger\Job\Utils\MoveStopOrdersWhenPositionMoved;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Messenger\DispatchAsync;
use Exception;

/**
 * @codeCoverageIgnore
 */
final class SchedulerFactory
{
    private const VERY_FAST = '700 milliseconds';
    private const FAST = '1000 milliseconds';
    private const MEDIUM = '1500 milliseconds';
    private const SLOW = '2 seconds';
    private const VERY_SLOW = '5 seconds';

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

        $jobSchedules = [
                                                                  /**** Warmup Ticker *****/
            PeriodicalJob::infinite('2023-02-24T23:49:01.29Z', \DateInterval::createFromDateString('650 milliseconds'), DispatchAsync::message(new UpdateTicker(Symbol::BTCUSDT))),

                                                        /**** Push relevant orders to Exchange *****/
            // SHORT-position | SL
            PeriodicalJob::infinite('2023-02-24T23:49:01.31Z', \DateInterval::createFromDateString($shortStopSpeed), new PushRelevantStopOrders(Symbol::BTCUSDT, Side::Sell)),

            // SHORT-position | BUY [async]
            PeriodicalJob::infinite('2023-02-24T23:49:01.37Z', \DateInterval::createFromDateString($shortBuySpeed), DispatchAsync::message(new PushRelevantBuyOrders(Symbol::BTCUSDT, Side::Sell))),

            // LONG-position | SL
            PeriodicalJob::infinite('2023-01-18T00:00:01.77Z', \DateInterval::createFromDateString($longStopSpeed), new PushRelevantStopOrders(Symbol::BTCUSDT, Side::Buy)),

            // LONG-position | BUY [async]
            PeriodicalJob::infinite('2023-01-18T00:00:02.22Z', \DateInterval::createFromDateString($longBuySpeed), DispatchAsync::message(new PushRelevantBuyOrders(Symbol::BTCUSDT, Side::Buy))),

                                                                   /**** Utils *****/
            /** Cleanup orders **/
            PeriodicalJob::infinite('2023-02-24T23:49:05Z', \sprintf('PT%s', ($cleanupPeriod = '15S')), DispatchAsync::message(new FixupOrdersDoubling(OrderType::Stop, Side::Sell, 6, 3, true))),
            PeriodicalJob::infinite('2023-02-24T23:49:06Z', \sprintf('PT%s', $cleanupPeriod),           DispatchAsync::message(new FixupOrdersDoubling(OrderType::Add, Side::Sell, 1, 3, false))),
            PeriodicalJob::infinite('2023-02-24T23:49:07Z', \sprintf('PT%s', $cleanupPeriod),           DispatchAsync::message(new FixupOrdersDoubling(OrderType::Stop, Side::Buy, 1, 3, true))),
            PeriodicalJob::infinite('2023-02-24T23:49:08Z', \sprintf('PT%s', $cleanupPeriod),           DispatchAsync::message(new FixupOrdersDoubling(OrderType::Add, Side::Buy, 1, 2, true))),

            /** Move SL **/
            PeriodicalJob::infinite('2023-02-24T23:49:08Z', 'PT30S', DispatchAsync::message(new MoveStopOrdersWhenPositionMoved(Side::Sell))),

//            PeriodicalJob::infinite('2023-01-18T00:01:08Z', 'PT30S', DispatchAsync::message(
//                new TryReleaseActiveOrders(symbol: Symbol::BTCUSDT, force: true)
//            )),
        ];

        return new Scheduler($clock, $jobSchedules);
    }
}
