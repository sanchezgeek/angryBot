<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use App\Application\Messenger\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Market\TransferFundingFees;
use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Messenger\Job\Cache\UpdateTicker;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrders;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStops;
use App\Bot\Application\Messenger\Job\Utils\FixupOrdersDoubling;
use App\Bot\Application\Messenger\Job\Utils\MoveStops;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Domain\Position\ValueObject\Side;
use App\Messenger\Async;
use App\Worker\AppContext;
use App\Worker\RunningWorker;
use DateInterval;
use DateTimeImmutable;

use function sprintf;

/**
 * @codeCoverageIgnore
 */
final class SchedulerFactory
{
    private const VERY_FAST = '700 milliseconds';
    private const FAST = '1 second';
    private const MEDIUM = '1200 milliseconds';
    private const SLOW = '2 seconds';
    private const VERY_SLOW = '5 seconds';

    private const CONF = [
        'short.sl'  => self::VERY_FAST, 'short.buy' => self::FAST,
        'long.sl'   => self::VERY_FAST, 'long.buy'  => self::FAST,
    ];

    public static function createScheduler(ClockInterface $clock): Scheduler
    {
        $runningWorker = AppContext::runningWorker();

        $jobSchedules = match ($runningWorker) {
            RunningWorker::SHORT => self::short(),
            RunningWorker::LONG  => self::long(),
            RunningWorker::UTILS => self::utils(),
            RunningWorker::CACHE => self::cache(),
            default => [],
        };

        return new Scheduler($clock, $jobSchedules);
    }

    private static function short(): array
    {
        return [
            PeriodicalJob::create('2023-09-25T00:00:01.77Z', self::interval(self::CONF['short.sl']), new PushStops(Symbol::BTCUSDT, Side::Sell)),
            PeriodicalJob::create('2023-09-25T00:00:01.01Z', self::interval(self::CONF['short.buy']), Async::message(new PushBuyOrders(Symbol::BTCUSDT, Side::Sell))),
        ];
    }

    private static function long(): array
    {
        return [
            PeriodicalJob::create('2023-09-25T00:00:01.41Z', self::interval(self::CONF['long.sl']), new PushStops(Symbol::BTCUSDT, Side::Buy)),
            PeriodicalJob::create('2023-09-20T00:00:02.11Z', self::interval(self::CONF['long.buy']), Async::message(new PushBuyOrders(Symbol::BTCUSDT, Side::Buy))),
        ];
    }

    private static function utils(): array
    {
        $cleanupPeriod = '45S';

        return [
            PeriodicalJob::create('2023-02-24T23:49:05Z', sprintf('PT%s', $cleanupPeriod), Async::message(new FixupOrdersDoubling(OrderType::Stop, Side::Sell, 30, 6, true))),
            PeriodicalJob::create('2023-02-24T23:49:06Z', sprintf('PT%s', $cleanupPeriod), Async::message(new FixupOrdersDoubling(OrderType::Add, Side::Sell, 15, 3, false))),
            PeriodicalJob::create('2023-02-24T23:49:07Z', sprintf('PT%s', $cleanupPeriod), Async::message(new FixupOrdersDoubling(OrderType::Stop, Side::Buy, 30, 6, true))),
            PeriodicalJob::create('2023-02-24T23:49:08Z', sprintf('PT%s', $cleanupPeriod), Async::message(new FixupOrdersDoubling(OrderType::Add, Side::Buy, 15, 3, false))),

            # position
            PeriodicalJob::create('2023-09-24T23:49:08Z', 'PT30S', Async::message(new MoveStops(Side::Sell))),
            PeriodicalJob::create('2023-09-24T23:49:09Z', 'PT3S', Async::message(new CheckPositionIsUnderLiquidation(Symbol::BTCUSDT, Side::Sell))),

            PeriodicalJob::create('2023-09-24T23:49:10Z', 'PT30S', Async::message(new MoveStops(Side::Buy))),
            PeriodicalJob::create('2023-09-24T23:49:11Z', 'PT3S', Async::message(new CheckPositionIsUnderLiquidation(Symbol::BTCUSDT, Side::Buy))),

            # market
            PeriodicalJob::create('2023-12-01T00:00:00.67Z', 'PT8H', Async::message(new TransferFundingFees(Symbol::BTCUSDT))),

            # orders
            PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT5S', Async::message(new TryReleaseActiveOrders(symbol: Symbol::BTCUSDT, force: true))),
        ];
    }

    private static function cache(): array
    {
        $start = new DateTimeImmutable('2023-09-25T00:00:01.11Z');
        $ttl = '2 seconds';
        $interval = 'PT3S';

        if (($procNum = AppContext::procNum()) > 0) {
            $start = $start->add(self::interval(sprintf('%d milliseconds', $procNum * 900)));
        } // else {// $ttl = '6 seconds'; $interval = 'PT5S';}

        return [
            /**
             * Cache for two seconds, because there are two cache workers (so any other worker no need to do request to get ticker)
             * @see ../../../docker/etc/supervisor.d/bot-consumers.ini [program:cache]
             */
            PeriodicalJob::create($start, $interval, new UpdateTicker(Symbol::BTCUSDT, self::interval($ttl))),
        ];
    }

    private static function interval(string $datetime): DateInterval
    {
        return DateInterval::createFromDateString($datetime);
    }

    private function __construct()
    {
    }
}
