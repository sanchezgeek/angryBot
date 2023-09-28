<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\V5Api\Request\Trade;

use App\Bot\Domain\ValueObject\Order\ExecutionOrderType;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\V5Api\Request\Trade\Enum\TimeInForceParam;
use App\Infrastructure\ByBit\V5Api\Request\Trade\Enum\TriggerByParam;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function sprintf;

/**
 * @see https://bybit-exchange.github.io/docs/v5/order/create-order
 *
 * @see \App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Trade\PlaceOrderRequestTest
 */
final readonly class PlaceOrderRequest extends AbstractByBitApiRequest
{
    public function method(): string
    {
        return Request::METHOD_POST;
    }

    public function url(): string
    {
        return '/v5/order/create';
    }

    public function data(): array
    {
        return [
            'category' => $this->category,
            'symbol' => $this->symbol,
            'side' => ucfirst($this->side->value),
            'orderType' => $this->orderType->value,
            'triggerBy' => $this->triggerBy->value,
            'timeInForce' => $this->timeInForceParam->value,
            'reduceOnly' => $this->reduceOnly,
            'closeOnTrigger' => $this->closeOnTrigger,
            'qty' => (string)$this->qty,
            'triggerPrice' => (string)$this->triggerPrice,
//            'positionIdx' Used to identify positions in different position modes. Under hedge-mode, this param is required (USDT perps & Inverse contracts have hedge mode)
            //0: one-way mode
            //1: hedge-mode Buy side
            //2: hedge-mode Sell side
        ];
    }

    /**
     * @todo | Research minOrderQty API-param
     */
    public function __construct(
        private string $category,
        private string $symbol,
        private Side $side,
        private ExecutionOrderType $orderType,
        private TriggerByParam $triggerBy,
        private TimeInForceParam $timeInForceParam,
        private bool $reduceOnly,
        private bool $closeOnTrigger,
        private float $qty,
        private float $triggerPrice,
    ) {
        assert($this->qty > 0, new InvalidArgumentException(
            sprintf('%s: $qty must be greater than zero (`%f` provided)', __CLASS__, $this->qty)
        ));

        assert($this->triggerPrice > 0, new InvalidArgumentException(
            sprintf('%s: $triggerPrice must be greater than zero (`%f` provided)', __CLASS__, $this->qty)
        ));
    }
}
