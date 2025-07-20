<?php

declare(strict_types=1);

namespace App\Messenger\SchedulerTransport;

use App\Alarm\Application\Messenger\Job\Balance\CheckBalance;
use App\Alarm\Application\Messenger\Job\CheckAlarm;
use App\Application\Messenger\Account\ApiKey\CheckApiKeyDeadlineDay;
use App\Application\Messenger\Market\TransferFundingFees;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidation;
use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Messenger\Job\BuyOrder\CheckOrdersNowIsActive;
use App\Bot\Application\Messenger\Job\BuyOrder\ResetBuyOrdersActiveState\ResetBuyOrdersActiveState;
use App\Bot\Application\Messenger\Job\Cache\UpdateTicker;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrders;
use App\Bot\Application\Messenger\Job\Utils\MoveStops;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Clock\ClockInterface;
use App\Connection\Application\Messenger\Job\CheckConnection;
use App\Domain\Coin\Coin;
use App\Domain\Position\ValueObject\Side;
use App\Helper\OutputHelper;
use App\Infrastructure\Symfony\Messenger\Async\AsyncMessage;
use App\Liquidation\Application\Job\RemoveStaleStops\RemoveStaleStopsMessage;
use App\Screener\Application\Job\CheckSymbolsPriceChange\CheckSymbolsPriceChange;
use App\Service\Infrastructure\Job\CheckMessengerMessages\CheckMessengerMessages;
use App\Service\Infrastructure\Job\Ping\PingMessages;
use App\Service\Infrastructure\Job\RestartWorker\RestartWorkerMessage;
use App\Stop\Application\Job\MoveOpenedPositionStopsToBreakeven\MoveOpenedPositionStopsToBreakeven;
use App\Stop\Application\UseCase\Push\MainPositionsStops\PushAllMainPositionsStops;
use App\Stop\Application\UseCase\Push\RestPositionsStops\PushAllRestPositionsStops;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Watch\Application\Job\CheckMainPositionIsInLoss\CheckPositionIsInLoss;
use App\Watch\Application\Job\CheckPositionIsInProfit\CheckPositionIsInProfit;
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
    private const string VERY_FAST = '700 milliseconds';
    private const string FAST = '1 second';
    private const string MEDIUM = '1200 milliseconds';
    private const string SLOW = '2 seconds';
    private const string MEDIUM_SLOW = '3 seconds';
    private const string VERY_SLOW = '4 seconds';
    private const string VERY_VERY_SLOW = '8 seconds';

    private const string PUSH_BUY_ORDERS_SPEED = self::VERY_VERY_SLOW;

    private const string PUSH_MAIN_POSITIONS_SL_SPEED = self::MEDIUM_SLOW;
    private const string PUSH_REST_POSITIONS_SL_SPEED = self::VERY_VERY_SLOW;

    private const array TICKERS_CACHE = ['interval' => 'PT3S', 'delay' => 900];
//    private const TICKERS_CACHE = ['interval' => 'PT7S', 'delay' => 2300];
//    private const TICKERS_CACHE = ['interval' => 'PT10S', 'delay' => 3300];

    public function __construct(
        private readonly PositionServiceInterface $positionService,
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly SymbolProvider $symbolProvider,
    ) {
    }

    public function createScheduler(ClockInterface $clock): Scheduler
    {
        $jobSchedules = match (AppContext::runningWorker()) {
            RunningWorker::BUY_ORDERS  => $this->buyOrders(),
            RunningWorker::SERVICE => $this->service(),
            RunningWorker::CRITICAL => $this->critical(),
            RunningWorker::MAIN_POSITIONS_STOPS => $this->mainStops(),
            RunningWorker::REST_POSITIONS_STOPS => $this->restStops(),
            default => [],
//            RunningWorker::CACHE => $this->cache(),
        };

        return new Scheduler($clock, $jobSchedules);
    }

    private function critical(): array
    {
        return [
            PeriodicalJob::create('2023-09-24T23:49:09Z', 'PT3S', new CheckPositionIsUnderLiquidation()),
        ];
    }

    private function mainStops(): array
    {
        OutputHelper::print('main positions stops worker started');

        return [
            PeriodicalJob::create('2023-09-24T23:49:08Z', 'PT30S', new PingMessages()),
            PeriodicalJob::create('2023-09-25T00:00:01.77Z', self::interval(self::PUSH_MAIN_POSITIONS_SL_SPEED), new PushAllMainPositionsStops())
        ];
    }

    private function restStops(): array
    {
        OutputHelper::print('rest positions stops worker started');

        return [
            PeriodicalJob::create('2023-09-25T00:00:01.77Z', self::interval(self::PUSH_REST_POSITIONS_SL_SPEED), new PushAllRestPositionsStops())
        ];
    }

    private function buyOrders(): array
    {
        $items = [];

        $notExecutedOrdersSymbols = $this->buyOrderRepository->getNotExecutedOrdersSymbolsMap();
        foreach ($notExecutedOrdersSymbols as $symbolRaw => $positionSides) {
            $symbol = $this->symbolProvider->getOneByName($symbolRaw);
            // var_dump(sprintf('%s => %s', $symbol->name(), implode(', ', array_map(static fn (Side $side) => $side->title(), $positionSides))));

            foreach ($positionSides as $positionSide) {
                $items[] = PeriodicalJob::create('2023-09-25T00:00:01.01Z', self::interval(self::PUSH_BUY_ORDERS_SPEED), new PushBuyOrders($symbol, $positionSide));
            }
        }

        $items[] = PeriodicalJob::create('2023-09-24T23:49:08Z', 'PT1M', new RestartWorkerMessage());

        return $items;
    }

    private function service(): array
    {
        $priceCheckInterval = 'PT10M';
        $priceCheckIntervalLong = 'PT20M';

        return [
            # service // PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT1M', AsyncMessage::for(new GenerateSupervisorConfigs())),

            PeriodicalJob::create('2023-09-24T23:49:08Z', $priceCheckInterval, AsyncMessage::for(new CheckSymbolsPriceChange(Coin::USDT))),
            PeriodicalJob::create('2023-09-24T23:49:08Z', $priceCheckInterval, AsyncMessage::for(new CheckSymbolsPriceChange(Coin::USDT, 1))),
            PeriodicalJob::create('2023-09-24T23:49:08Z', $priceCheckInterval, AsyncMessage::for(new CheckSymbolsPriceChange(Coin::USDT, 2))),
            PeriodicalJob::create('2023-09-24T23:49:08Z', $priceCheckIntervalLong, AsyncMessage::for(new CheckSymbolsPriceChange(Coin::USDT, 3))),
            PeriodicalJob::create('2023-09-24T23:49:08Z', $priceCheckIntervalLong, AsyncMessage::for(new CheckSymbolsPriceChange(Coin::USDT, 4))),
            PeriodicalJob::create('2023-09-24T23:49:08Z', $priceCheckIntervalLong, AsyncMessage::for(new CheckSymbolsPriceChange(Coin::USDT, 5))),

            PeriodicalJob::create('2023-09-24T23:49:08Z', 'PT30S', new CheckMessengerMessages()),
            PeriodicalJob::create('2023-09-24T23:49:08Z', 'PT3H', new CheckApiKeyDeadlineDay()),

            // $cleanupPeriod = '45S';
            // PeriodicalJob::create('2023-02-24T23:49:05Z', sprintf('PT%s', $cleanupPeriod), AsyncMessage::for(new FixupOrdersDoubling(Symbol::BTCUSDT, OrderType::Stop, Side::Sell, 30, 6, true))),
            // PeriodicalJob::create('2023-02-24T23:49:06Z', sprintf('PT%s', $cleanupPeriod), AsyncMessage::for(new FixupOrdersDoubling(OrderType::Add, Side::Sell, 15, 3, false))),
            // PeriodicalJob::create('2023-02-24T23:49:07Z', sprintf('PT%s', $cleanupPeriod), AsyncMessage::for(new FixupOrdersDoubling(Symbol::BTCUSDT, OrderType::Stop, Side::Buy, 30, 6, true))),
            // PeriodicalJob::create('2023-02-24T23:49:08Z', sprintf('PT%s', $cleanupPeriod), AsyncMessage::for(new FixupOrdersDoubling(OrderType::Add, Side::Buy, 15, 3, false))),

            # market
            PeriodicalJob::create('2023-12-01T00:00:00.67Z', 'PT8H', AsyncMessage::for(new TransferFundingFees(SymbolEnum::BTCUSDT))),

            # alarm
            PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT20S', AsyncMessage::for(new CheckAlarm())),
            PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT30S', AsyncMessage::for(new CheckBalance())),

            # connection
            PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT1M', AsyncMessage::for(new CheckConnection())),

            # !!! position !!!
            // --- stops
            PeriodicalJob::create('2023-09-24T23:49:08Z', 'PT2M', AsyncMessage::for(new MoveStops(SymbolEnum::BTCUSDT, Side::Sell))),
            PeriodicalJob::create('2023-09-24T23:49:10Z', 'PT2M', AsyncMessage::for(new MoveStops(SymbolEnum::BTCUSDT, Side::Buy))),

            PeriodicalJob::create('2023-09-24T23:49:10Z', 'PT10S', AsyncMessage::for(new MoveOpenedPositionStopsToBreakeven(targetPositionPnlPercent: -100, excludeFixationsStop: true))),

            // -- watch
            PeriodicalJob::create('2023-09-24T23:49:09Z', 'PT2M', AsyncMessage::for(new CheckPositionIsInLoss())),
            PeriodicalJob::create('2023-09-24T23:49:09Z', 'PT1M', AsyncMessage::for(new CheckPositionIsInProfit())),

            // -- active BuyOrders
            PeriodicalJob::create('2023-09-24T23:49:09Z', 'PT30S', AsyncMessage::for(new CheckOrdersNowIsActive())),
            PeriodicalJob::create('2023-09-24T23:49:09Z', 'PT10M', AsyncMessage::for(new ResetBuyOrdersActiveState())),

            // -- active Conditional orders
            PeriodicalJob::create('2023-09-18T00:01:08Z', 'PT40S', AsyncMessage::for(new TryReleaseActiveOrders(force: true))),

            // -- liquidation
            PeriodicalJob::create('2025-07-01T01:01:08Z', 'P1D', AsyncMessage::for(new RemoveStaleStopsMessage())),
        ];
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
            PeriodicalJob::create($start, $interval, new UpdateTicker(self::interval($ttl), SymbolEnum::BTCUSDT)),
        ];
    }

    private static function interval(string $datetime): DateInterval
    {
        return DateInterval::createFromDateString($datetime);
    }
}
