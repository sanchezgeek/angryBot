<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity;

use App\Bot\Domain\Entity\Common\HasExchangeOrderContext;
use App\Bot\Domain\Entity\Common\HasOriginalPriceContext;
use App\Bot\Domain\Entity\Common\HasSupportContext;
use App\Bot\Domain\Entity\Common\HasVolume;
use App\Bot\Domain\Entity\Common\HasWithoutOppositeContext;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Contract\OrderTypeAwareInterface;
use App\Domain\Order\Contract\VolumeSignAwareInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Stop\Event\StopPushedToExchange;
use App\Domain\Stop\Helper\PnlHelper;
use App\EventBus\HasEvents;
use App\EventBus\RecordEvents;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

use function round;
use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\Entity\StopTest
 */
#[ORM\Entity(repositoryClass: StopRepository::class)]
class Stop implements HasEvents, VolumeSignAwareInterface, OrderTypeAwareInterface
{
    public const IS_TP_CONTEXT = 'isTakeProfit';
    public const CLOSE_BY_MARKET_CONTEXT = 'closeByMarket';
    public const OPPOSITE_ORDERS_DISTANCE_CONTEXT = 'oppositeOrdersDistance';
    public const IS_ADDITIONAL_STOP_FROM_LIQUIDATION_HANDLER = 'additionalStopFromLiquidationHandler';
    public const FIX_HEDGE_ON_LOSS = 'fixHedgeOnLossEnabled';

    public const TP_TRIGGER_DELTA = 50;

    use HasVolume;
    use HasOriginalPriceContext;
    use HasExchangeOrderContext;
    use HasSupportContext;
    use HasWithoutOppositeContext;

    use RecordEvents;

    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column]
    private float $price;

    #[ORM\Column]
    private float $volume;

    #[ORM\Column(nullable: true)]
    private float $triggerDelta;

    #[ORM\Column(type: 'string', enumType: Symbol::class)]
    private Symbol $symbol;

    #[ORM\Column(type: 'string', enumType: Side::class)]
    private Side $positionSide;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $context = [];

    public function __construct(int $id, float $price, float $volume, ?float $triggerDelta, Symbol $symbol, Side $positionSide, array $context = [])
    {
        $this->id = $id;
        $this->price = $symbol->makePrice($price)->value();
        $this->volume = $symbol->roundVolume($volume);
        $this->triggerDelta = $triggerDelta ?? $symbol->stopDefaultTriggerDelta();
        $this->positionSide = $positionSide;
        $this->context = $context;
        $this->symbol = $symbol;
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @todo | new column
     */
    public function getSymbol(): Symbol
    {
        return $this->symbol;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): self
    {
        $this->setOriginalPrice($this->price);

        $price = $this->symbol->makePrice($price)->value();

        $this->price = $price;

        return $this;
    }

    public function addPrice(float $value): self
    {
        $price = $this->price + $value;
        $this->price = $this->symbol->makePrice($price)->value();

        return $this;
    }

    public function getVolume(): float
    {
        return $this->volume;
    }

    /**
     * @throws DomainException
     */
    public function subVolume(float $value): self
    {
        $restVolume = $this->volume - $value;

        if (!($restVolume >= $this->symbol->minOrderQty())) {
            throw new DomainException(
                sprintf('Cannot subtract %f from volume: the remaining volume (%f) must be >= $symbol->minOrderQty().', $value, $restVolume)
            );
        }

        $this->volume = $this->symbol->roundVolume($restVolume);

        return $this;
    }

    public function getPositionSide(): Side
    {
        return $this->positionSide;
    }

    public function getTriggerDelta(): ?float
    {
        return $this->triggerDelta;
    }

    public function increaseTriggerDelta(float $withValue): self
    {
        $this->triggerDelta = $this->symbol->makePrice($this->triggerDelta + $withValue)->value();

        return $this;
    }

    public function setTriggerDelta(float $triggerDelta): self
    {
        $this->triggerDelta = $this->symbol->makePrice($triggerDelta)->value();

        return $this;
    }

    public function getContext(string $name = null): mixed
    {
        return $name ? ($this->context[$name] ?? null) : $this->context;
    }

    public function isTakeProfitOrder(): bool
    {
        return ($this->context[self::IS_TP_CONTEXT] ?? null) === true;
    }

    public function setIsTakeProfitOrder(): self
    {
        $this->context[self::IS_TP_CONTEXT] = true;

        /**
         * @todo | пока что такой костыль, т.к. для того, чтобы PushStopsHandler нашёл этот ордер, нужна trigger_delta
         * @see StopRepository::findActive() + $nearTicker
         */
        $this->setTriggerDelta($this->getPrice() / 600);

        return $this;
    }

    public function wasPushedToExchange(string $thisExchangeOrderId): self
    {
        $this->recordThat(new StopPushedToExchange($this));

        return $this->setExchangeOrderId($thisExchangeOrderId);
    }

    public function isCloseByMarketContextSet(): bool
    {
        return ($this->context[self::CLOSE_BY_MARKET_CONTEXT] ?? null) === true;
    }

    public function setIsCloseByMarketContext(): self
    {
        $this->context[self::CLOSE_BY_MARKET_CONTEXT] = true;

        return $this;
    }

    public function isAdditionalStopFromLiquidationHandler(): bool
    {
        return ($this->context[self::IS_ADDITIONAL_STOP_FROM_LIQUIDATION_HANDLER] ?? null) === true;
    }

    public function setIsAdditionalStopFromLiquidationHandler(): self
    {
        $this->context[self::IS_ADDITIONAL_STOP_FROM_LIQUIDATION_HANDLER] = true;

        return $this;
    }

    public function isFixHedgeOnLossEnabled(): bool
    {
        return ($this->context[self::FIX_HEDGE_ON_LOSS] ?? null) === true;
    }

    public function setIsFixHedgeOnLossEnabled(): self
    {
        $this->context[self::FIX_HEDGE_ON_LOSS] = true;

        return $this;
    }

    public static function getTakeProfitContext(): array
    {
        return [self::IS_TP_CONTEXT => true];
    }

    public static function getTakeProfitTriggerDelta(): float
    {
        return self::TP_TRIGGER_DELTA;
    }

    public function getPnlInPercents(Position $position): float
    {
        return round(PnlHelper::getPnlInPercents($position, $this->price), 2);
    }

    public function getPnlUsd(Position $position): float
    {
        $sign = $position->side->isShort() ? -1 : +1;
        $delta = $this->price - $position->entryPrice;

        // @todo | or it's right only for BTCUSDT contracts?
        return $sign * $delta * $this->getVolume();
//        $pnl = $this->getPnlInPercents($position) / 100;
//        $positionPart = $this->volume / $position->size;
//        $orderCost = $position->size * ($position->entryPrice / 100) * $positionPart;
//        return $orderCost * $pnl;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'positionSide' => $this->positionSide->value,
            'symbol' => $this->symbol->value,
            'price' => $this->price,
            'volume' => $this->volume,
            'triggerDelta' => $this->triggerDelta,
            'context' => $this->context,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['price'],
            $data['volume'],
            $data['triggerDelta'],
            Symbol::from($data['symbol']),
            Side::from($data['positionSide']),
            $data['context']
        );
    }

    public function info(): array
    {
        return [
            'symbol' => $this->getSymbol(),
            'side' => $this->positionSide,
            'price' => $this->price,
            'volume' => $this->volume,
            'triggerDelta' => $this->triggerDelta,
            'context' => $this->context,
        ];
    }

    public function signedVolume(): float
    {
        return -$this->volume;
    }

    public function getOrderType(): OrderType
    {
        return OrderType::Stop;
    }

    public function isOrderPushedToExchange(): bool
    {
        return $this->getExchangeOrderId() !== null;
    }

    public function getOppositeBuyOrderDistance(): ?float
    {
        return $this->context[self::OPPOSITE_ORDERS_DISTANCE_CONTEXT] ?? null;
    }

    public function setOppositeOrdersDistanceContext(float $distance): self
    {
        $this->context[self::OPPOSITE_ORDERS_DISTANCE_CONTEXT] = $distance;

        return $this;
    }
}
