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
use App\Worker\AppContext;
use App\Worker\RunningWorker;
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
        $jobSchedules = match (AppContext::runningWorker()) {
            RunningWorker::SHORT  => [
                // SHORT-position | SL
                PeriodicalJob::infinite(
                    '2023-02-25T00:00:01.77Z', \DateInterval::createFromDateString(self::VERY_FAST),
                    new PushRelevantStopOrders(Symbol::BTCUSDT, Side::Sell)
                ),

                // SHORT-position | BUY [async]
                PeriodicalJob::infinite(
                    '2023-02-25T00:00:01.01Z', \DateInterval::createFromDateString(self::FAST),
                    DispatchAsync::message(new PushRelevantBuyOrders(Symbol::BTCUSDT, Side::Sell))
                ),
            ],
            RunningWorker::LONG => [
                // LONG-position | SL
                PeriodicalJob::infinite(
                    '2023-02-25T00:00:01.41Z', \DateInterval::createFromDateString(self::FAST),
                    new PushRelevantStopOrders(Symbol::BTCUSDT, Side::Buy)
                ),

                // LONG-position | BUY [async]
                PeriodicalJob::infinite(
                    '2023-02-25T00:00:02.11Z', \DateInterval::createFromDateString(self::FAST),
                    DispatchAsync::message(new PushRelevantBuyOrders(Symbol::BTCUSDT, Side::Buy))
                ),
            ],
            RunningWorker::UTILS => [
                // Cleanup orders
                PeriodicalJob::infinite(
                    '2023-02-24T23:49:05Z', \sprintf('PT%s', ($cleanupPeriod = '15S')),
                    DispatchAsync::message(new FixupOrdersDoubling(OrderType::Stop, Side::Sell, 5, 2, true))
                ),
                PeriodicalJob::infinite(
                    '2023-02-24T23:49:06Z', \sprintf('PT%s', $cleanupPeriod),
                    DispatchAsync::message(new FixupOrdersDoubling(OrderType::Add, Side::Sell, 1, 3, false))
                ),
                PeriodicalJob::infinite(
                    '2023-02-24T23:49:07Z', \sprintf('PT%s', $cleanupPeriod),
                    DispatchAsync::message(new FixupOrdersDoubling(OrderType::Stop, Side::Buy, 1, 3, true))
                ),
                PeriodicalJob::infinite(
                    '2023-02-24T23:49:08Z', \sprintf('PT%s', $cleanupPeriod),
                    DispatchAsync::message(new FixupOrdersDoubling(OrderType::Add, Side::Buy, 1, 2, true))
                ),

                // Move SL
                PeriodicalJob::infinite('2023-02-24T23:49:08Z', 'PT30S', DispatchAsync::message(new MoveStopOrdersWhenPositionMoved(Side::Sell))),

                // Release orders
                PeriodicalJob::infinite('2023-01-18T00:01:08Z', 'PT15S', DispatchAsync::message(new TryReleaseActiveOrders(symbol: Symbol::BTCUSDT, force: true))),
            ],
            RunningWorker::CACHE => self::cache(),
            RunningWorker::ASYNC, RunningWorker::DEF => [],
        };

        return new Scheduler($clock, $jobSchedules);
    }

    private static function cache(): array
    {
        $start = new \DateTimeImmutable('2023-02-25T00:00:01.11Z');

        if (AppContext::procNum() > 0) {
            $start = $start->add(
                \DateInterval::createFromDateString(
                    \sprintf('%d milliseconds', AppContext::procNum() * 650)
                )
            );
        }

        return [
            /**
             * Cache for two seconds, because there are two cache workers (so any order worker no need to do request to get ticker)
             * @see ../../../docker/etc/supervisor.d/bot-consumers.ini [program:cache]
             */
            PeriodicalJob::infinite($start, 'PT2S', new UpdateTicker(Symbol::BTCUSDT, new \DateInterval('PT1S'))),
        ];
    }
}
