<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity;

use App\Bot\Domain\Entity\Common\HasExchangeOrderContext;
use App\Bot\Domain\Entity\Common\HasVolume;
use App\Bot\Domain\Entity\Common\HasWithoutOppositeContext;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Ticker;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use App\EventBus\HasEvents;
use App\EventBus\RecordEvents;
use Doctrine\ORM\Mapping as ORM;

/**
 * @see \App\Tests\Unit\Domain\Entity\BuyOrderTest
 */
#[ORM\Entity(repositoryClass: BuyOrderRepository::class)]
class BuyOrder implements HasEvents
{
    use HasWithoutOppositeContext;

    public const SPOT_TRANSFERS_COUNT_CONTEXT = 'cannotAffordContext.spotTransfers.successTransfersCount';
    public const SUPPORT_FIXATIONS_COUNT_CONTEXT = 'hedgeSupportTakeProfit.fixationsCount';
    public const WITH_SHORT_STOP_CONTEXT = 'withShortStop';

    /**
     * @todo | It's only about BuyOrder? No =( If no ByBit is used as an exchange
     */
    public const ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT = 'onlyAfterExchangeOrderExecuted';
    public const STOP_DISTANCE_CONTEXT = 'stopDistance';

    use HasVolume;
    use HasExchangeOrderContext;

    use RecordEvents;

    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column]
    private float $price;

    #[ORM\Column]
    private float $volume;

    #[ORM\Column(type: 'string', enumType: Side::class)]
    private Side $positionSide;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $context = [];

    public function __construct(int $id, Price|float $price, float $volume, Side $positionSide, array $context = [])
    {
        $this->id = $id;
        $this->price = $price instanceof Price ? $price->value() : $price;
        $this->volume = $volume;
        $this->positionSide = $positionSide;
        $this->context = $context;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPositionSide(): Side
    {
        return $this->positionSide;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): void
    {
        $this->price = $price;
    }

    public function getVolume(): float
    {
        return $this->volume;
    }

    public function getContext(string $name = null): mixed
    {
        return $name ? ($this->context[$name] ?? null) : $this->context;
    }

    public function mustBeExecuted(Ticker $ticker): bool
    {
        return $ticker->isIndexAlreadyOverBuyOrder($this->positionSide, $this->price);
    }

    public function isWithShortStop(): bool
    {
        return ($this->context[self::WITH_SHORT_STOP_CONTEXT] ?? null) === true;
    }

    public function setOnlyAfterExchangeOrderExecutedContext(string $exchangeOrderId): self
    {
        $this->context[self::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT] = $exchangeOrderId;

        return $this;
    }

    public function getOnlyAfterExchangeOrderExecutedContext(): ?string
    {
        return $this->context[self::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT] ?? null;
    }

    public function getStopDistance(): ?float
    {
        return $this->context[self::STOP_DISTANCE_CONTEXT] ?? null;
    }

    public function incSuccessSpotTransfersCounter(): self
    {
        $this->context[self::SPOT_TRANSFERS_COUNT_CONTEXT] = $this->getSuccessSpotTransfersCount() + 1;

        return $this;
    }

    public function getSuccessSpotTransfersCount(): int
    {
        return $this->context[self::SPOT_TRANSFERS_COUNT_CONTEXT] ?? 0;
    }

    public function incHedgeSupportFixationsCounter(): self
    {
        $this->context[self::SUPPORT_FIXATIONS_COUNT_CONTEXT] = $this->getHedgeSupportFixationsCount() + 1;

        return $this;
    }

    public function getHedgeSupportFixationsCount(): int
    {
        return $this->context[self::SUPPORT_FIXATIONS_COUNT_CONTEXT] ?? 0;
    }
}
