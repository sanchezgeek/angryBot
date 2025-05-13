<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use App\Alarm\Application\Messenger\Job\Balance\CheckBalance;
use App\Alarm\Application\Messenger\Job\CheckAlarm;
use App\Application\Messenger\Account\ApiKey\CheckApiKeyDeadlineDay;
use App\Application\Messenger\Market\TransferFundingFees;
use App\Application\Messenger\Position\CheckMainPositionIsInLoss\CheckPositionIsInLoss;
use App\Application\Messenger\Position\CheckPositionIsInProfit\CheckPositionIsInProfit;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Position\SyncPositions\CheckOpenedPositionsSymbolsMessage;
use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Messenger\Job\BuyOrder\CheckOrdersNowIsActive;
use App\Bot\Application\Messenger\Job\Cache\UpdateTicker;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrders;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStops;
use App\Bot\Application\Messenger\Job\Utils\MoveStops;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Connection\Application\Messenger\Job\CheckConnection;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\Symfony\Messenger\Async\AsyncMessage;
use App\Service\Infrastructure\Job\CheckMessengerMessages\CheckMessengerMessages;
use App\Service\Infrastructure\Job\GenerateSupervisorConfigs\GenerateSupervisorConfigs;
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
    private const MEDIUM_SLOW = '3 seconds';
    private const VERY_SLOW = '5 seconds';
    private const VERY_VERY_SLOW = '9 seconds';

    private const SPEED_CONF = [
        'sl'  => self::MEDIUM_SLOW,
        'buy' => self::VERY_VERY_SLOW,
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
            RunningWorker::BUY_ORDERS  => $this->buyOrders(),
            RunningWorker::UTILS => $this->utils(),
            RunningWorker::CACHE => $this->cache(),
            RunningWorker::CRITICAL => $this->critical(),
            RunningWorker::SYMBOL_DEDICATED => $this->symbol(),
            default => [],
        };

        return new Scheduler($clock, $jobSchedules);
    }

    private function critical(): array
    {
        return [
            PeriodicalJob::create('2023-09-24T23:49:09Z', 'PT3S', new CheckPositionIsUnderLiquidation()),
        ];
    }

    private function symbol(): array
    {
        $symbol = Symbol::from($_ENV['PROCESSED_SYMBOL']);
        $processedOrders = $_ENV['PROCESSED_ORDERS'];

        var_dump(sprintf('%s %s started', $symbol->value, $processedOrders));

        if ($processedOrders === 'Stop') {
            return [
                PeriodicalJob::create('2023-09-25T00:00:01.77Z', self::interval(self::SPEED_CONF['sl']), new PushStops($symbol, Side::Sell)),
                PeriodicalJob::create('2023-09-25T00:00:01.41Z', self::interval(self::SPEED_CONF['sl']), new PushStops($symbol, Side::Buy)),
            ];
        } elseif ($processedOrders === 'BuyOrder') {
            return [
                PeriodicalJob::create('2023-09-25T00:00:01.01Z', self::interval(self::SPEED_CONF['buy']), new PushBuyOrders($symbol, Side::Sell)),
                PeriodicalJob::create('2023-09-20T00:00:02.11Z', self::interval(self::SPEED_CONF['buy']), new PushBuyOrders($symbol, Side::Buy)),
            ];
        }

        return [];
    }

    private function buyOrders(): array
    {
        $items = [];

        foreach ($this->getOpenedPositionsSymbols() as $symbol) {
            $items[] = PeriodicalJob::create('2023-09-25T00:00:01.01Z', self::interval(self::SPEED_CONF['buy']), new PushBuyOrders($symbol, Side::Sell));
            $items[] = PeriodicalJob::create('2023-09-25T00:00:01.01Z', self::interval(self::SPEED_CONF['buy']), new PushBuyOrders($symbol, Side::Buy));
        }

        return $items;
    }

    private function utils(): array
    {
        $cleanupPeriod = '45S';

        $items = [
            # service
            PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT1M', AsyncMessage::for(new GenerateSupervisorConfigs())),
            PeriodicalJob::create('2023-09-24T23:49:08Z', 'PT30S', AsyncMessage::for(new CheckMessengerMessages())),
            PeriodicalJob::create('2023-09-24T23:49:08Z', 'PT3H', AsyncMessage::for(new CheckApiKeyDeadlineDay())),

            // PeriodicalJob::create('2023-02-24T23:49:05Z', sprintf('PT%s', $cleanupPeriod), AsyncMessage::for(new FixupOrdersDoubling(Symbol::BTCUSDT, OrderType::Stop, Side::Sell, 30, 6, true))),
            // PeriodicalJob::create('2023-02-24T23:49:06Z', sprintf('PT%s', $cleanupPeriod), AsyncMessage::for(new FixupOrdersDoubling(OrderType::Add, Side::Sell, 15, 3, false))),
            // PeriodicalJob::create('2023-02-24T23:49:07Z', sprintf('PT%s', $cleanupPeriod), AsyncMessage::for(new FixupOrdersDoubling(Symbol::BTCUSDT, OrderType::Stop, Side::Buy, 30, 6, true))),
            // PeriodicalJob::create('2023-02-24T23:49:08Z', sprintf('PT%s', $cleanupPeriod), AsyncMessage::for(new FixupOrdersDoubling(OrderType::Add, Side::Buy, 15, 3, false))),

            # market
            PeriodicalJob::create('2023-12-01T00:00:00.67Z', 'PT8H', AsyncMessage::for(new TransferFundingFees(Symbol::BTCUSDT))),

            # alarm
            PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT20S', AsyncMessage::for(new CheckAlarm())),
            PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT1M', AsyncMessage::for(new CheckBalance())),

            # connection
            PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT1M', AsyncMessage::for(new CheckConnection())),

            # symbols
            PeriodicalJob::create('2023-09-24T23:49:09Z', 'PT1M', AsyncMessage::for(new CheckOpenedPositionsSymbolsMessage())),

            # !!! position !!!
            // --- stops
            PeriodicalJob::create('2023-09-24T23:49:08Z', 'PT2M', AsyncMessage::for(new MoveStops(Symbol::BTCUSDT, Side::Sell))),
            PeriodicalJob::create('2023-09-24T23:49:10Z', 'PT2M', AsyncMessage::for(new MoveStops(Symbol::BTCUSDT, Side::Buy))),

            // -- main positions loss
            PeriodicalJob::create('2023-09-24T23:49:09Z', 'PT2M', AsyncMessage::for(new CheckPositionIsInLoss())),

            // -- positions profit
            PeriodicalJob::create('2023-09-24T23:49:09Z', 'PT1M', AsyncMessage::for(new CheckPositionIsInProfit())),

            // -- active BuyOrders
            PeriodicalJob::create('2023-09-24T23:49:09Z', 'PT30S', AsyncMessage::for(new CheckOrdersNowIsActive())),

            // -- active Conditional orders
            PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT40S', AsyncMessage::for(new TryReleaseActiveOrders(force: true))),
        ];

        return $items;
    }

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
    private function getOpenedPositionsSymbols(array $except = []): array
    {
        return $this->positionService->getOpenedPositionsSymbols($except);
    }
}
