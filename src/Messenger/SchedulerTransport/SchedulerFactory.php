<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use App\Alarm\Application\Messenger\Job\CheckAlarm;
use App\Application\Messenger\Market\TransferFundingFees;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Position\SyncPositions\CheckOpenedPositionsSymbolsMessage;
use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Messenger\Job\Cache\UpdateTicker;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrders;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStops;
use App\Bot\Application\Messenger\Job\Utils\FixupOrdersDoubling;
use App\Bot\Application\Messenger\Job\Utils\MoveStops;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Connection\Application\Messenger\Job\CheckConnection;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\Symfony\Messenger\Async\AsyncMessage;
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
    private const VERY_VERY_SLOW = '10 seconds';

    private const CONF = [
        'short.sl'  => self::VERY_FAST, 'short.buy' => self::FAST,
        'long.sl'   => self::VERY_FAST, 'long.buy'  => self::FAST,
    ];

    private const TICKERS_CACHE = ['interval' => 'PT3S', 'delay' => 900];
//    private const TICKERS_CACHE = ['interval' => 'PT7S', 'delay' => 2300];
//    private const TICKERS_CACHE = ['interval' => 'PT10S', 'delay' => 3300];

    public function __construct(
        private readonly PositionServiceInterface $positionService,
    ) {
    }

    public function createScheduler(ClockInterface $clock): Scheduler
    {
        $jobSchedules = match (AppContext::runningWorker()) {
            RunningWorker::SHORT => $this->short(),
            RunningWorker::LONG  => $this->long(),
            RunningWorker::UTILS => $this->utils(),
            RunningWorker::CACHE => $this->cache(),
            default => [],
        };

        return new Scheduler($clock, $jobSchedules);
    }

    private function short(): array
    {
        $items = [
            PeriodicalJob::create('2023-09-25T00:00:01.77Z', self::interval(self::CONF['short.sl']), new PushStops(Symbol::BTCUSDT, Side::Sell)),
            PeriodicalJob::create('2023-09-25T00:00:01.01Z', self::interval(self::CONF['short.buy']), AsyncMessage::for(new PushBuyOrders(Symbol::BTCUSDT, Side::Sell))),
        ];

        foreach ($this->getOtherOpenedPositionsSymbols() as $symbol) {
            $items[] = PeriodicalJob::create('2023-09-25T00:00:01.77Z', self::interval(self::MEDIUM), new PushStops($symbol, Side::Sell));
            $items[] = PeriodicalJob::create('2023-09-25T00:00:01.01Z', self::interval(self::SLOW), AsyncMessage::for(new PushBuyOrders($symbol, Side::Sell)));
        }

        return $items;
    }

    private function long(): array
    {
        $items = [
            PeriodicalJob::create('2023-09-25T00:00:01.41Z', self::interval(self::CONF['long.sl']), new PushStops(Symbol::BTCUSDT, Side::Buy)),
            PeriodicalJob::create('2023-09-20T00:00:02.11Z', self::interval(self::CONF['long.buy']), AsyncMessage::for(new PushBuyOrders(Symbol::BTCUSDT, Side::Buy))),
        ];

        foreach ($this->getOtherOpenedPositionsSymbols() as $symbol) {
            $items[] = PeriodicalJob::create('2023-09-25T00:00:01.77Z', self::interval(self::MEDIUM), new PushStops($symbol, Side::Buy));
            $items[] = PeriodicalJob::create('2023-09-25T00:00:01.01Z', self::interval(self::SLOW), AsyncMessage::for(new PushBuyOrders($symbol, Side::Buy)));
        }

        return $items;
    }

    private function utils(): array
    {
        $cleanupPeriod = '45S';

        $items = [
            PeriodicalJob::create('2023-02-24T23:49:05Z', sprintf('PT%s', $cleanupPeriod), AsyncMessage::for(new FixupOrdersDoubling(Symbol::BTCUSDT, OrderType::Stop, Side::Sell, 30, 6, true))),
            // PeriodicalJob::create('2023-02-24T23:49:06Z', sprintf('PT%s', $cleanupPeriod), AsyncMessage::for(new FixupOrdersDoubling(OrderType::Add, Side::Sell, 15, 3, false))),
            PeriodicalJob::create('2023-02-24T23:49:07Z', sprintf('PT%s', $cleanupPeriod), AsyncMessage::for(new FixupOrdersDoubling(Symbol::BTCUSDT, OrderType::Stop, Side::Buy, 30, 6, true))),
            // PeriodicalJob::create('2023-02-24T23:49:08Z', sprintf('PT%s', $cleanupPeriod), AsyncMessage::for(new FixupOrdersDoubling(OrderType::Add, Side::Buy, 15, 3, false))),

            # position
            PeriodicalJob::create('2023-09-24T23:49:08Z', 'PT45S', AsyncMessage::for(new MoveStops(Symbol::BTCUSDT, Side::Sell))),
            PeriodicalJob::create('2023-09-24T23:49:10Z', 'PT45S', AsyncMessage::for(new MoveStops(Symbol::BTCUSDT, Side::Buy))),

            # market
            PeriodicalJob::create('2023-12-01T00:00:00.67Z', 'PT8H', AsyncMessage::for(new TransferFundingFees(Symbol::BTCUSDT))),

            # orders
            PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT12S', AsyncMessage::for(new TryReleaseActiveOrders(symbol: Symbol::BTCUSDT, force: true))),

            # alarm
            PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT10S', AsyncMessage::for(new CheckAlarm())),

            # connection
            PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT15S', AsyncMessage::for(new CheckConnection())),

            # position liquidation
            PeriodicalJob::create('2023-09-24T23:49:09Z', 'PT5S', AsyncMessage::for(new CheckPositionIsUnderLiquidation(Symbol::BTCUSDT))),

            # symbols
            PeriodicalJob::create('2023-09-24T23:49:09Z', 'PT5M', AsyncMessage::for(new CheckOpenedPositionsSymbolsMessage())),
        ];

        # release other symbols orders
        foreach ($this->getOtherOpenedPositionsSymbols() as $symbol) {
            $items[] = PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT60S', AsyncMessage::for(new TryReleaseActiveOrders(symbol: $symbol, force: true)));

            $additionalStopDistanceWithLiquidation = self::ADDITIONAL_STOP_LIQUIDATION_DISTANCE[$symbol->value] ?? null;
            $acceptableStoppedPart = self::ACCEPTABLE_STOPPED_PART[$symbol->value] ?? null;
            !in_array($symbol, self::SKIP_LIQUIDATION_CHECK_ON_SYMBOLS) && $items[] = PeriodicalJob::create('2023-09-24T23:49:09Z', 'PT10S', AsyncMessage::for(
                new CheckPositionIsUnderLiquidation(
                    symbol: $symbol,
                    percentOfLiquidationDistanceToAddStop: $additionalStopDistanceWithLiquidation,
                    acceptableStoppedPart: $acceptableStoppedPart,
                )
            ));
        }

        return $items;
    }

    private const SKIP_LIQUIDATION_CHECK_ON_SYMBOLS = [
    ];

    private const ADDITIONAL_STOP_LIQUIDATION_DISTANCE = [
    ];

    private const ACCEPTABLE_STOPPED_PART = [
    ];

    private function cache(): array
    {
        $start = new DateTimeImmutable('2023-09-25T00:00:01.11Z');
        $ttl = '2 seconds';
        $interval = self::TICKERS_CACHE['interval'];

        // можно какой-то воркер, который будет проверять, что не больше какого-то промежутка
        if (($procNum = AppContext::procNum()) > 0) {
            $start = $start->add(self::interval(sprintf('%d milliseconds', $procNum * self::TICKERS_CACHE['delay'])));
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

    /**
     * @return Symbol[]
     */
    private function getOtherOpenedPositionsSymbols(): array
    {
        return $this->positionService->getOpenedPositionsSymbols([Symbol::BTCUSDT]);
    }
}
