<?php

declare(strict_types=1);

namespace App\Bot\Infrastructure\ByBit;

use App\Bot\Application\Events\Exchange\PositionUpdated;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Order\ExecutionOrderType;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use Lin\Bybit\BybitLinear;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Polyfill\Intl\Icu\Exception\NotImplementedException;

/**
 * ** RIP **
 *
 * @deprecated
 */
final class PositionService implements PositionServiceInterface
{
//    private const URL = 'https://api-testnet.bybit.com';
    private const URL = 'https://api.bybit.com';

    private const POSITION_TTL = '6 seconds';

    private BybitLinear $api;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly CacheInterface $cache,
        private readonly EventDispatcherInterface $events,
        private readonly ExchangeServiceInterface $exchangeService,
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
                        $item['entry_price'],
                        $item['size'],
                        $item['position_value'],
                        $item['liq_price'],
                        $item['position_margin'],
                        $item['leverage'],
                    );
                }
            }

            $position && $this->events->dispatch(new PositionUpdated($position));

            return $position;
        });
    }

    public function getPositions(Symbol $symbol): array
    {
        throw new NotImplementedException(sprintf('%s: not implemented', __METHOD__));
    }

    /**
     * @inheritDoc
     *
     * @throws MaxActiveCondOrdersQntReached
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     */
    public function addConditionalStop(Position $position, float $price, float $qty, TriggerBy $triggerBy): string
    {
        $result = $this->api->privates()->postStopOrderCreate([
            //'order_link_id'=>'xxxxxxxxxxxxxx',
            'side' => \ucfirst($position->side === Side::Sell ? Side::Buy->value : Side::Sell->value),
            'symbol' => $position->symbol->value,
            'trigger_by' => $triggerBy->value,
            'reduce_only' => 'true',
            'close_on_trigger' => 'false',
            'base_price' => $this->exchangeService->ticker($position->symbol)->indexPrice->value(),
            'order_type' => ExecutionOrderType::Market->value,
            'qty' => $position->symbol->roundVolume($qty),
            'stop_px' => PriceHelper::round($price),
            'time_in_force' => 'GoodTillCancel',
        ]);

        if ($result['ret_code'] === 130033 && $result['ret_msg'] === 'already had 10 working normal stop orders') {
            throw new MaxActiveCondOrdersQntReached($result['ret_msg']);
        }

        if ($result['ret_code'] === 10006 && $result['ret_msg'] === 'Too many visits. Exceeded the API Rate Limit.') {
            throw new ApiRateLimitReached($result['ret_msg']);
        }

        if ($result['ret_code'] !== 0) {
            throw new UnexpectedApiErrorException($result['ret_code'], $result['ret_msg'], __METHOD__);
        }

        return $result['result']['stop_order_id'];
    }

    /**
     * Просто оставлю это здесь =)
     *
     * @inheritDoc
     *
     * @throws CannotAffordOrderCostException
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     */
    public function marketBuy(Position $position, float $qty): string
    {
        $price = $this->exchangeService->ticker($position->symbol)->markPrice;

        $result = $this->api->privates()->postOrderCreate([
            'side' => \ucfirst($position->side === Side::Sell ? Side::Sell->value : Side::Buy->value),
            'symbol' => $position->symbol->value,
            'trigger_by' => 'IndexPrice',
            'reduce_only' => 'false',
            'close_on_trigger' => 'false',
            'base_price' => $price->value(),
            'order_type' => ExecutionOrderType::Market->value,
            'qty' => $position->symbol->roundVolume($qty),
            'trigger_price' => $price->value(),
            'time_in_force' => 'GoodTillCancel',
        ]);

        if ($result['ret_code'] === 130021 && \str_contains($result['ret_msg'], 'CannotAffordOrderCost')) {
            throw CannotAffordOrderCostException::forBuy($position->symbol, $position->side, $qty);
        }

        if ($result['ret_code'] === 10006 && $result['ret_msg'] === 'Too many visits. Exceeded the API Rate Limit.') {
            throw new ApiRateLimitReached($result['ret_msg']);
        }

        if ($result['ret_code'] !== 0) {
            throw new UnexpectedApiErrorException($result['ret_code'], $result['ret_msg'], __METHOD__);
        }

        return $result['result']['order_id'];
    }

    public function getOpenedPositionsSymbols(): array
    {
        return [];
    }

    public function getOpenedPositionsRawSymbols(): array
    {
        return [];
    }
}
