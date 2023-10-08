<?php

declare(strict_types=1);

namespace App\Bot\Infrastructure\ByBit;

use App\Bot\Application\Events\Exchange\TickerUpdated;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Helper\Json;
use App\Infrastructure\ByBit\TickersCache;
use App\Messenger\SchedulerTransport\SchedulerFactory;
use App\Worker\AppContext;
use Lin\Bybit\BybitLinear;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

final class ExchangeService implements ExchangeServiceInterface, TickersCache
{
    private const URL = 'https://api.bybit.com';
//    private const URL_ORDERS = 'https://api.bybit.com/v5/order/realtime';
    private const URL_ORDERS = 'https://api.bybit.com/contract/v3/private/order/list';

    private const DEFAULT_TICKER_TTL = '1000 milliseconds';

    private BybitLinear $api;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly EventDispatcherInterface $events,
        private readonly CacheItemPoolInterface $cache,
    ) {
        $this->api = new BybitLinear($this->apiKey, $this->apiSecret, self::URL);
    }

    public function ticker(Symbol $symbol): Ticker
    {
        $item = $this->cache->getItem(
            $this->tickerCacheKey($symbol)
        );

        if ($item->isHit()) {
            return $item->get();
        }

        return $this->updateTicker($symbol, \DateInterval::createFromDateString(self::DEFAULT_TICKER_TTL));
    }

    /**
     * @internal
     *
     * Do not do any checks. Just get from exchange and update cache for 2 seconds.
     *
     * @see SchedulerFactory::createScheduler() -> "Warmup ticker data"
     */
    public function updateTicker(Symbol $symbol, \DateInterval $ttl): Ticker
    {
        $key = $this->tickerCacheKey($symbol);

        $ticker = $this->getTicker($symbol);

        $item = $this->cache->getItem($key)->set($ticker)->expiresAfter($ttl);

        $this->cache->save($item);

        return $ticker;
    }

    private function getTicker(Symbol $symbol): Ticker
    {
        $data = $this->api->publics()->getTickers(['symbol' => $symbol->value]);

        \assert(isset($data['result']), 'Ticker not found');

        $markPrice = (float)$data['result'][0]['mark_price'];
        $indexPrice = (float)$data['result'][0]['index_price'];

        $ticker = new Ticker($symbol, $markPrice, $indexPrice, AppContext::workerHash());

        $this->events->dispatch(new TickerUpdated($ticker));

        return $ticker;
    }

    private function tickerCacheKey(Symbol $symbol): string
    {
        return \sprintf('ticker_%s', $symbol->value);
    }

    public function closeActiveConditionalOrder(ActiveStopOrder $order): void
    {
        $result = $this->api->privates()->postStopOrderCancel(
            [
                'symbol' => $order->symbol->value,
                'stop_order_id' => $order->orderId
            ]
        );

        if ($result['ret_code'] !== 0) {
            throw new \Exception(
                \sprintf('Cannot close order %s (%s | %s)', $order->orderId, $order->triggerPrice, $order->volume)
            );
        }
    }

    public function activeConditionalOrders(Symbol $symbol): array
    {
        $params = [
            'symbol' => $symbol->value,
            'stop_order_status' => 'Untriggered',
        ];

        $data = $this->api->privates()->getStopOrderList($params);

        $items = [];
        foreach ($data['result']['data'] as $item) {
            // Only orders created by bot
            if (!($item['reduce_only'] && !$item['close_on_trigger'])) {
                continue;
            }

            $items[] = new ActiveStopOrder(
                $symbol,
                $item['side'] === 'Buy' ? Side::Sell : Side::Buy,
                $item['stop_order_id'],
                $item['qty'],
                $item['trigger_price'],
                $item['trigger_by'],
            );
        }

        return $items;


//        $nonce=floor(microtime(true) * 1000);
//        $this->data['api_key']=$this->key;
//        $this->data['timestamp']=$this->nonce;
//
//        ksort($this->data);
//
//        $temp=$this->data;
//        foreach ($temp as $k=>$v) if(is_bool($v)) $temp[$k]=$v?'true':'false';
//
//        $this->signature = hash_hmac('sha256', urldecode(http_build_query($temp)), $this->apiSecret);
//
//        $this->headers=[
//            'Content-Type' => 'application/json',
//        ];
//
//        $this->options['headers']=$this->headers;
//        $this->options['timeout'] = $this->options['timeout'] ?? 60;
//
////

        $params = [
            'symbol' => $symbol->value,
            'orderFilter' => 'StopOrder',
            'openOnly' => 0
        ];

        $timestamp = time() * 1000;
        $params_for_signature= $timestamp . $this->apiKey . "5000" . \http_build_query($params);
        $signature = hash_hmac('sha256', $params_for_signature, $this->apiSecret);

        $req = [
            'headers' => [
                'X-BAPI-SIGN-TYPE' => 2,
                'X-BAPI-SIGN' => $signature,
                'X-BAPI-API-KEY' => $this->apiKey,
                'X-BAPI-TIMESTAMP' => $timestamp,
                'X-BAPI-RECV-WINDOW' => 5000,
                'Content-Type' => 'application/json',
            ],
            'query' => $params,
        ];


        $data = $this->client->request(Request::METHOD_GET, self::URL_ORDERS, $req);

        $data = Json::decode($data->getContent());

        var_dump($data);die;

//        $data = $this->api->privates()->getOrderList([
//            'symbol' => $symbol->value,
//            'order_type'=>'Market',
//        ]);

    }


//    /**
//     * @var CachedValue[]
//     */
//    private array $tickersHotCache = [];

//    private function getTicker(Symbol $symbol, ?\DateTimeImmutable $requestedAt = null): Ticker
//    {
//        if (!isset($this->tickersHotCache[$symbol->value])) {
//            $valueFactory = function () use ($symbol, $requestedAt) {
//                $data = $this->api->publics()->getTickers(['symbol' => $symbol->value]);
//
//                \assert(isset($data['result']), 'Ticker not found');
//
//                $markPrice = (float)$data['result'][0]['mark_price'];
//                $indexPrice = (float)$data['result'][0]['index_price'];
//
//                $ticker = new Ticker($symbol, $markPrice, $indexPrice, RunningContext::getRunningWorker());
//
//                $this->events->dispatch(new TickerUpdated($ticker, $requestedAt));
//
//                return $ticker;
//            };
//
//            $this->tickersHotCache[$symbol->value] = new CachedValue($valueFactory, 300); // no more than 3 times per second
//        }
//
//        return $this->tickersHotCache[$symbol->value]->get();
//    }

}
