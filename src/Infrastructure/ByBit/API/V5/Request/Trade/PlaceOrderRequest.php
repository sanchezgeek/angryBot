<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Trade;

use App\Bot\Domain\ValueObject\Order\ExecutionOrderType;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\Enum\Order\ConditionalOrderTriggerDirection;
use App\Infrastructure\ByBit\API\V5\Enum\Order\PositionIdx;
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

    public static function marketBuy(
        AssetCategory $category,
        Symbol $symbol,
        Side $positionSide,
        float $qty,
    ): self {
        $orderType = ExecutionOrderType::Market;
        $triggerBy = TriggerBy::IndexPrice;
        $timeInForce = TimeInForce::GTC;

        $side = $positionSide;
        $pIdx = self::getPositionIdx($positionSide);

        return new self($category, $symbol, $side, $orderType, $triggerBy, $timeInForce, false, false, $qty, $pIdx);
    }

    public static function marketClose(
        AssetCategory $category,
        Symbol $symbol,
        Side $positionSide,
        float $qty,
    ): self {
        $orderType = ExecutionOrderType::Market;
        $triggerBy = TriggerBy::IndexPrice;
        $timeInForce = TimeInForce::GTC;

        $side = $positionSide->getOpposite();
        $pIdx = self::getPositionIdx($positionSide);

        return new self($category, $symbol, $side, $orderType, $triggerBy, $timeInForce, true, false, $qty, $pIdx);
    }

    public static function limitTP(
        AssetCategory $category,
        Symbol $symbol,
        Side $positionSide,
        float $qty,
        float $price,
    ): self {
        $orderType = ExecutionOrderType::Limit;
        $triggerBy = TriggerBy::IndexPrice;
        $timeInForce = TimeInForce::GTC;

        $side = $positionSide->getOpposite();
        $posIdx = self::getPositionIdx($positionSide);

        $triggerDirection = $positionSide->isShort()
            ? ConditionalOrderTriggerDirection::FallsToTriggerPrice
            : ConditionalOrderTriggerDirection::RisesToTriggerPrice
        ;

        return new self($category, $symbol, $side, $orderType, $triggerBy, $timeInForce, true, false, $qty, $posIdx, $price, $triggerDirection);
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
        $posIdx = self::getPositionIdx($positionSide);
        $triggerDirection = $positionSide->isShort()
            ? ConditionalOrderTriggerDirection::RisesToTriggerPrice
            : ConditionalOrderTriggerDirection::FallsToTriggerPrice
        ;

        return new self($category, $symbol, $side, $orderType, $triggerBy, $timeInForce, true, false, $qty, $posIdx, $price, $triggerDirection);
    }

    private static function getPositionIdx(Side $positionSide): PositionIdx
    {
        // @todo | apiV5 | research 'positionIdx'
        // нужно разобраться как это повлияет, если хэджа не будет
        // Used to identify positions in different position modes. Under hedge-mode, this param is required (USDT perps & Inverse contracts have hedge mode)
        // 0: one-way mode
        // 1: hedge-mode Buy side
        // 2: hedge-mode Sell side
        return $positionSide->isShort()
            ? PositionIdx::HedgeModeSellSide
            : PositionIdx::HedgeModeBuySide
        ;
    }

    public function data(): array
    {
        $data = [
            'category' => $this->category->value,
            'symbol' => $this->symbol->value,
            'side' => ucfirst($this->side->value),
            'orderType' => $this->orderType->value,
            'timeInForce' => $this->timeInForce->value,
            'reduceOnly' => $this->reduceOnly,
            'closeOnTrigger' => $this->closeOnTrigger,
            'qty' => (string)$this->qty,
            'positionIdx' => $this->positionIdx->value,
        ];

        // Limit orders executed only by LastPrice
        if ($this->orderType !== ExecutionOrderType::Limit) {
            $data['triggerBy'] = $this->triggerBy->value;
        }

        if ($this->triggerPrice) {
            $price = (string)$this->triggerPrice;

            if ($this->orderType === ExecutionOrderType::Limit) {
                $data['price'] = $price;
            } else {
                $data['triggerPrice'] = $price;
            }
        }

        if ($this->triggerDirection) {
            $data['triggerDirection'] = $this->triggerDirection->value;
        }

        return $data;
    }

    /**
     * @todo | apiV5 | Research minOrderQty API-param
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
        private PositionIdx $positionIdx,
        private ?float $triggerPrice = null,
        private ?ConditionalOrderTriggerDirection $triggerDirection = null,
    ) {
        assert($this->qty > 0, new InvalidArgumentException(
            sprintf('%s: $qty must be greater than zero (`%f` provided)', __CLASS__, $this->qty)
        ));

        if ($this->orderType === ExecutionOrderType::Limit) {
            assert($this->triggerPrice !== null, new InvalidArgumentException(
                sprintf('%s: $triggerPrice must be set for limit order', __CLASS__)
            ));
        }

        if ($this->triggerPrice !== null) {
            assert($this->triggerPrice > 0, new InvalidArgumentException(
                sprintf('%s: $triggerPrice must be greater than zero (`%f` provided)', __CLASS__, $this->qty)
            ));
        }
    }
}
