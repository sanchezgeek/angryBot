<?php

declare(strict_types=1);

namespace App\Bot\Infrastructure\ByBit;

use App\Bot\Application\Events\Exchange\TickerUpdated;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Helper\Json;
use App\Value\CachedValue;
use Lin\Bybit\BybitLinear;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

final class ExchangeService implements ExchangeServiceInterface
{
    private const URL = 'https://api.bybit.com';
//    private const URL_ORDERS = 'https://api.bybit.com/v5/order/realtime';
    private const URL_ORDERS = 'https://api.bybit.com/contract/v3/private/order/list';

    private const TICKER_UPDATE_INTERVAL = 1000;
    private ?CachedValue $ticker = null;

    private BybitLinear $api;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly EventDispatcherInterface $events,
    ) {
        $this->api = new BybitLinear($this->apiKey, $this->apiSecret, self::URL);
    }

    public function getTicker(Symbol $symbol): Ticker
    {
        if ($this->ticker === null) {
            $valueFactory = function () use ($symbol) {
                $data = $this->api->publics()->getTickers(['symbol' => $symbol->value]);

                \assert(isset($data['result']), 'Ticker not found');

                $ticker = new Ticker(
                    $symbol, (float)$data['result'][0]['mark_price'], (float)$data['result'][0]['index_price']
                );

                $this->events->dispatch(new TickerUpdated($ticker));

                return $ticker;
            };

            $this->ticker = new CachedValue($valueFactory, self::TICKER_UPDATE_INTERVAL);
        }

        return $this->ticker->get();
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

    public function getActiveConditionalOrders(Symbol $symbol): array
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

}
