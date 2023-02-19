<?php

declare(strict_types=1);

namespace App\Bot\Infrastructure\ByBit;

use App\Bot\Application\Events\Exchange\PositionUpdated;
use App\Bot\Application\Exception\ApiRateLimitReached;
use App\Bot\Application\Exception\CannotAffordOrderCost;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Order\ExecutionOrderType;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Bot\Application\Exception\MaxActiveCondOrdersQntReached;
use App\Helper\VolumeHelper;
use Lin\Bybit\BybitLinear;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class PositionService implements PositionServiceInterface
{
//    private const URL = 'https://api-testnet.bybit.com';
    private const URL = 'https://api.bybit.com';

    private const POSITION_TTL = '15 seconds';

    private BybitLinear $api;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly CacheInterface $cache,
        private readonly EventDispatcherInterface $events,
    ) {
        $this->api = new BybitLinear($this->apiKey, $this->apiSecret, self::URL);
    }

    public function getPosition(Symbol $symbol, Side $side): ?Position
    {
        $key = \sprintf('position_data_%s_%s', $symbol->value, $side->value);

        return $this->cache->get($key, function (ItemInterface $item) use ($symbol, $side) {
            $item->expiresAfter(\DateInterval::createFromDateString(self::POSITION_TTL));

            $data = $this->api->privates()->getPositionList([
                'symbol' => $symbol->value,
            ]);

            $position = null;
            foreach ($data['result'] as $item) {
                if ($item['entry_price'] !== 0 && \strtolower($item['side']) === $side->value) {
                    $position = new Position(
                        $side,
                        $symbol,
                        VolumeHelper::round($item['entry_price'], 2),
                        $item['size'],
                        VolumeHelper::round($item['position_value'], 2),
                        $item['liq_price'],
                    );
                }
            }

            $position && $this->events->dispatch(new PositionUpdated($position));

            return $position;
        });
    }

    public function getOppositePosition(Position $position): ?Position
    {
        return $this->getPosition($position->symbol, $position->side->getOpposite());
    }

    /**
     * @return string Created stop order id
     */
    public function addStop(Position $position, Ticker $ticker, float $price, float $qty): ?string
    {
        try {
            $result = $this->api->privates()->postStopOrderCreate([
                //'order_link_id'=>'xxxxxxxxxxxxxx',
                'side' => \ucfirst($position->side === Side::Sell ? Side::Buy->value : Side::Sell->value),
                'symbol' => $position->symbol->value,
                'trigger_by' => 'IndexPrice',
                'reduce_only' => 'true',
                'close_on_trigger' => 'false',
                'base_price' => $ticker->indexPrice,
                'order_type' => ExecutionOrderType::Market->value,
                'qty' => $qty,
                'stop_px' => $price,
                'time_in_force' => 'GoodTillCancel',
            ]);

            if ($result['ret_code'] === 130033 && $result['ret_msg'] === 'already had 10 working normal stop orders') {
                throw new MaxActiveCondOrdersQntReached($result['ret_msg']);
            }

            if ($result['ret_code'] === 130021 && \str_contains($result['ret_msg'], 'CannotAffordOrderCost')) {
                throw CannotAffordOrderCost::forBuy($position->symbol, $position->side, $qty);
            }

            if ($result['ret_code'] === 10006 && $result['ret_msg'] === 'Too many visits. Exceeded the API Rate Limit.') {
                throw new ApiRateLimitReached($result['ret_msg']);
            }

            if ($result['ret_code'] !== 0) {
                var_dump($result);
            }

            return $result['result']['stop_order_id'];
        } catch (MaxActiveCondOrdersQntReached|CannotAffordOrderCost|ApiRateLimitReached $e) {
            throw $e;
        } catch (\Exception $e) {
            print_r($e->getMessage());
        }

        return null;
    }

    public function addBuyOrder(Position $position, Ticker $ticker, float $price, float $qty): ?string
    {
        try {
            $result = $this->api->privates()->postOrderCreate([
                'side' => \ucfirst($position->side === Side::Sell ? Side::Sell->value : Side::Buy->value),
                'symbol' => $position->symbol->value,
                'trigger_by' => 'IndexPrice',
                'reduce_only' => 'false',
                'close_on_trigger' => 'false',
                'base_price' => $ticker->markPrice,
                'order_type' => ExecutionOrderType::Market->value,
                'qty' => $qty,
                'trigger_price' => $price,
//                'stop_px' => $price,
                'time_in_force' => 'GoodTillCancel',
            ]);

            if ($result['ret_code'] === 130033 && $result['ret_msg'] === 'already had 10 working normal stop orders') {
                throw new MaxActiveCondOrdersQntReached($result['ret_msg']);
            }

            if ($result['ret_code'] === 130021 && \str_contains($result['ret_msg'], 'CannotAffordOrderCost')) {
                throw CannotAffordOrderCost::forBuy($position->symbol, $position->side, $qty);
            }

            if ($result['ret_code'] === 10006 && $result['ret_msg'] === 'Too many visits. Exceeded the API Rate Limit.') {
                throw new ApiRateLimitReached($result['ret_msg']);
            }

            if ($result['ret_code'] !== 0) {
                var_dump($result);
            }

            return $result['result']['order_id'];
        } catch (MaxActiveCondOrdersQntReached|CannotAffordOrderCost|ApiRateLimitReached $e) {
            throw $e;
        } catch (\Exception $e) {
            print_r($e->getMessage());
        }

        return null;
    }

    public function addStop2(Position $position, Ticker $ticker, float $price): void
    {
        try {
            $result = $this->api->privates()->postOrderCreate([
                //'order_link_id'=>'xxxxxxxxxxxxxx',
                'side' => $position->side === Side::Sell ? Side::Buy->value : Side::Sell->value,
                'symbol' => $position->symbol->value,
                'order_type' => 'Limit',
                'qty' => 0.001,
                'price' => $price,
                'time_in_force' => 'GoodTillCancel',

                'reduce_only' => 'false',
                'close_on_trigger' => 'false',
            ]);
            print_r($result);
        } catch (\Exception $e) {
            print_r($e->getMessage());
        }
    }
}
