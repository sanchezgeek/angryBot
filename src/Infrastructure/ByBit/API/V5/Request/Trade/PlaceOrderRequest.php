<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Trade;

use App\Bot\Domain\ValueObject\Order\ExecutionOrderType;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Enum\Order\TimeInForce;
use App\Infrastructure\ByBit\API\V5\Enum\Order\TriggerBy;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function sprintf;
use function ucfirst;

/**
 * @see https://bybit-exchange.github.io/docs/v5/order/create-order
 *
 * @see \App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Trade\PlaceOrderRequestTest
 */
final readonly class PlaceOrderRequest extends AbstractByBitApiRequest
{
    public const URL = '/v5/order/create';

    public function method(): string
    {
        return Request::METHOD_POST;
    }

    public function url(): string
    {
        return self::URL;
    }

    public static function buyOrderImmediatelyTriggeredByIndexPrice(
        AssetCategory $category,
        Symbol $symbol,
        Side $positionSide,
        float $qty,
        float $price,
    ): self {
        $orderType = ExecutionOrderType::Market;
        $triggerBy = TriggerBy::IndexPrice;
        $timeInForce = TimeInForce::GTC;

        $side = $positionSide;

        return new self($category, $symbol, $side, $orderType, $triggerBy, $timeInForce, false, false, $qty, $price);
    }

    public static function stopConditionalOrderTriggeredByIndexPrice(
        AssetCategory $category,
        Symbol $symbol,
        Side $positionSide,
        float $qty,
        float $price,
    ): self {
        $orderType = ExecutionOrderType::Market;
        $triggerBy = TriggerBy::IndexPrice;
        $timeInForce = TimeInForce::GTC;

        $side = $positionSide->getOpposite();

        return new self($category, $symbol, $side, $orderType, $triggerBy, $timeInForce, true, false, $qty, $price);
    }

    public function data(): array
    {
        return [
            'category' => $this->category->value,
            'symbol' => $this->symbol->value,
            'side' => ucfirst($this->side->value),
            'orderType' => $this->orderType->value,
            'triggerBy' => $this->triggerBy->value,
            'timeInForce' => $this->timeInForce->value,
            'reduceOnly' => $this->reduceOnly,
            'closeOnTrigger' => $this->closeOnTrigger,
            'qty' => (string)$this->qty,
            'triggerPrice' => (string)$this->triggerPrice,
            // @todo | research 'positionIdx'
            // Used to identify positions in different position modes. Under hedge-mode, this param is required (USDT perps & Inverse contracts have hedge mode)
            // 0: one-way mode
            // 1: hedge-mode Buy side
            // 2: hedge-mode Sell side
        ];
    }

    /**
     * @todo | Research minOrderQty API-param
     */
    private function __construct(
        private AssetCategory $category,
        private Symbol $symbol,
        private Side $side,
        private ExecutionOrderType $orderType,
        private TriggerBy $triggerBy,
        private TimeInForce $timeInForce,
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
