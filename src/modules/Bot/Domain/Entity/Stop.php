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
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Stop\Event\StopPushedToExchange;
use App\Domain\Stop\Helper\PnlHelper;
use App\EventBus\HasEvents;
use App\EventBus\RecordEvents;
use App\Helper\VolumeHelper;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

use function round;
use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\Entity\StopTest
 */
#[ORM\Entity(repositoryClass: StopRepository::class)]
class Stop implements HasEvents
{
    public const MIN_VOLUME = 0.001;

    public const IS_TP_CONTEXT = 'isTakeProfit';
    public const CLOSE_BY_MARKET_CONTEXT = 'closeByMarket';
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
    private ?float $triggerDelta = null;

    #[ORM\Column(type: 'string', enumType: Side::class)]
    private Side $positionSide;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $context = [];

    public function __construct(int $id, float $price, float $volume, ?float $triggerDelta, Side $positionSide, array $context = [])
    {
        $this->id = $id;
        $this->price = $price;
        $this->volume = $volume;
        $this->triggerDelta = $triggerDelta;
        $this->positionSide = $positionSide;
        $this->context = $context;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): self
    {
        $this->setOriginalPrice($this->price);

        $this->price = $price;

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

        if (!($restVolume >= self::MIN_VOLUME)) {
            throw new DomainException(
                sprintf('Cannot subtract %f from volume: the remaining volume (%f) must be >= 0.001.', $value, $restVolume)
            );
        }

        $this->volume = VolumeHelper::round($restVolume);

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

    public function setTriggerDelta(float $triggerDelta): self
    {
        $this->triggerDelta = $triggerDelta;

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
         * @see \App\Bot\Domain\Repository\StopRepository::findActive() + $nearTicker
         */
        $this->setTriggerDelta(self::TP_TRIGGER_DELTA);

        return $this;
    }

    public function wasPushedToExchange(Symbol $symbol, string $exchangeOrderId): self
    {
        $this->recordThat(new StopPushedToExchange($this, $symbol));

        return $this->setExchangeOrderId($exchangeOrderId);
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
            Side::from($data['positionSide']),
            $data['context']
        );
    }
}
