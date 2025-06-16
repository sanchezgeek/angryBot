<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity;

use App\Bot\Domain\Entity\Common\HasExchangeOrderContext;
use App\Bot\Domain\Entity\Common\HasVolume;
use App\Bot\Domain\Entity\Common\HasWithoutOppositeContext;
use App\Bot\Domain\Entity\Common\WithOppositeOrderDistanceContext;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Buy\Domain\Enum\PredefinedStopLengthSelector;
use App\Buy\Domain\ValueObject\StopStrategy\Factory\StopCreationStrategyDefinitionStaticFactory;
use App\Buy\Domain\ValueObject\StopStrategy\StopCreationStrategyDefinition;
use App\Buy\Domain\ValueObject\StopStrategy\Strategy\PredefinedStopLength;
use App\Domain\BuyOrder\Enum\BuyOrderState;
use App\Domain\BuyOrder\Event\BuyOrderPushedToExchange;
use App\Domain\Order\Contract\OrderTypeAwareInterface;
use App\Domain\Order\Contract\VolumeSignAwareInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\EventBus\HasEvents;
use App\EventBus\RecordEvents;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\SymbolContainerInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/**
 * @see \App\Tests\Unit\Domain\Entity\BuyOrderTest
 */
#[ORM\Entity(repositoryClass: BuyOrderRepository::class)]
class BuyOrder implements HasEvents, VolumeSignAwareInterface, OrderTypeAwareInterface, SymbolContainerInterface
{
    use HasWithoutOppositeContext;

    public const string SPOT_TRANSFERS_COUNT_CONTEXT = 'cannotAffordContext.spotTransfers.successTransfersCount';
    public const string SUPPORT_FIXATIONS_COUNT_CONTEXT = 'hedgeSupportTakeProfit.fixationsCount';
    public const string FORCE_BUY_CONTEXT = 'forceBuy';
    public const string ONLY_IF_HAS_BALANCE_AVAILABLE_CONTEXT = 'onlyIfHasAvailableBalance';

    /**
     * @todo | It's only about BuyOrder? No =( If no ByBit is used as an exchange
     */
    public const string ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT = 'onlyAfterExchangeOrderExecuted';
    public const string IS_OPPOSITE_AFTER_SL_CONTEXT = 'isOppositeBuyOrderAfterStopLoss';
    public const string OPPOSITE_SL_ID_CONTEXT = 'oppositeForStopId';

    public const string ACTIVE_STATE_CHANGE_TIMESTAMP_CONTEXT = 'activeStateSetAtTimestamp';

    public const string STOP_LENGTH_DEFINITION_TYPE = 'stopLengthDefinition';

    use HasVolume;
    use HasExchangeOrderContext;
    use WithOppositeOrderDistanceContext;
    use RecordEvents;

    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column]
    private float $price;

    #[ORM\Column]
    private float $volume;

    #[ORM\ManyToOne(targetEntity: Symbol::class, fetch: 'EAGER')]
    #[ORM\JoinColumn(name: 'symbol', referencedColumnName: 'name')]
    private SymbolInterface $symbol;

    #[ORM\Column(type: 'string', enumType: Side::class)]
    private Side $positionSide;

    #[ORM\Column(type: 'string', enumType: BuyOrderState::class, options: ['default' => 'idle'])]
    private BuyOrderState $state = BuyOrderState::Idle;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $context = [];

    private bool $isOppositeStopExecuted = false;
    private ?StopCreationStrategyDefinition $stopCreationStrategyDefinition = null;

    public function __construct(int $id, SymbolPrice|float $price, float $volume, SymbolInterface $symbol, Side $positionSide, array $context = [])
    {
        $this->id = $id;
        $this->price = $symbol->makePrice(SymbolPrice::toFloat($price))->value();
        $this->volume = $symbol->roundVolume($volume);
        $this->positionSide = $positionSide;
        $this->context = $context;
        $this->symbol = $symbol;
    }

    public function getStopCreationDefinition(): StopCreationStrategyDefinition
    {
        if ($this->stopCreationStrategyDefinition !== null) {
            return $this->stopCreationStrategyDefinition;
        }

        if ($data = $this->context[self::STOP_LENGTH_DEFINITION_TYPE] ?? null) {
            $value = StopCreationStrategyDefinitionStaticFactory::fromData($data);
        } else {
            $value = new PredefinedStopLength(
                PredefinedStopLengthSelector::Standard
            );
        }

        return $this->stopCreationStrategyDefinition = $value;
    }

    public function setStopCreationStrategy(StopCreationStrategyDefinition $definition): self
    {
        $this->stopCreationStrategyDefinition = $definition;

        $this->context[self::STOP_LENGTH_DEFINITION_TYPE] = [
            StopCreationStrategyDefinition::TYPE_STORED_KEY => $definition::getType(),
            StopCreationStrategyDefinition::PARAMS_STORED_KEY => $definition->toArray(),
        ];

        return $this;
    }

    /**
     * @internal For tests
     */
    public function replaceSymbolEntity(Symbol $symbol): self
    {
        $this->symbol = $symbol;

        return $this;
    }

    public function wasPushedToExchange(string $exchangeOrderId): self
    {
        $this->recordThat(new BuyOrderPushedToExchange($this));

        return $this->setExchangeOrderId($exchangeOrderId);
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @todo | new column
     */
    public function getSymbol(): SymbolInterface
    {
        return $this->symbol;
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

    public function addPrice(float $value): self
    {
        $this->price += $value;
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

    public function getContext(?string $name = null): mixed
    {
        return $name ? ($this->context[$name] ?? null) : $this->context;
    }

    public function mustBeExecuted(Ticker $ticker): bool
    {
        return $this->isOrderActive() && $ticker->isIndexAlreadyOverBuyOrder($this->positionSide, $this->price);
    }

    public function isForceBuyOrder(): bool
    {
        return ($this->context[self::FORCE_BUY_CONTEXT] ?? null) === true;
    }

    public function setIsForceBuyOrderContext(): self
    {
        $this->context[self::FORCE_BUY_CONTEXT] = true;

        return $this;
    }

    public function unsetForceBuyOrderContext(): self
    {
        $this->context[self::FORCE_BUY_CONTEXT] = false;

        return $this;
    }

    public function isOnlyIfHasAvailableBalanceContextSet(): bool
    {
        return ($this->context[self::ONLY_IF_HAS_BALANCE_AVAILABLE_CONTEXT] ?? null) === true;
    }

    public function isOppositeBuyOrderAfterStopLoss(): bool
    {
        return ($this->context[self::IS_OPPOSITE_AFTER_SL_CONTEXT] ?? null) === true;
    }

    public function setIsOppositeBuyOrderAfterStopLossContext(): self
    {
        $this->context[self::IS_OPPOSITE_AFTER_SL_CONTEXT] = true;

        return $this;
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

    public function getOppositeStopId(): ?int
    {
        return $this->context[self::OPPOSITE_SL_ID_CONTEXT] ?? null;
    }

    public function setOppositeStopId(int $stopId): self
    {
        $this->context[self::OPPOSITE_SL_ID_CONTEXT] = $stopId;

        return $this;
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

    public function info(): array
    {
        return [
            'symbol' => $this->getSymbol(),
            'side' => $this->positionSide,
            'price' => $this->price,
            'volume' => $this->volume,
            'context' => $this->context,
        ];
    }

    public function signedVolume(): float
    {
        return $this->volume;
    }

    public function getOrderType(): OrderType
    {
        return OrderType::Add;
    }

    public function setIsOppositeStopExecuted(): self
    {
        $this->isOppositeStopExecuted = true;

        return $this;
    }

    /**
     * @todo It's currently being set in OrdersTotalInfoCommand by search in $pushed Stops
     *       => It must be moved to some handler OR change mechanic of creation instead
     */
    public function isOppositeStopExecuted(): bool
    {
        return $this->isOppositeStopExecuted;
    }

    public function setActive(DateTimeImmutable $datetime): self
    {
        $this->state = BuyOrderState::Active;
        $this->setActiveStateChangeTimestamp($datetime);

        return $this;
    }

    public function setIdle(): self
    {
        $this->state = BuyOrderState::Idle;
        $this->resetActiveStateChangeTimestamp();

        return $this;
    }

    public function isOrderActive(): bool
    {
        return $this->state === BuyOrderState::Active;
    }

    public function getActiveStateChangeTimestamp(): ?int
    {
        return $this->context[self::ACTIVE_STATE_CHANGE_TIMESTAMP_CONTEXT] ?? null;
    }

    public function setActiveStateChangeTimestamp(DateTimeImmutable $time): self
    {
        $this->context[self::ACTIVE_STATE_CHANGE_TIMESTAMP_CONTEXT] = $time->getTimestamp();

        return $this;
    }

    public function resetActiveStateChangeTimestamp(): self
    {
        unset($this->context[self::ACTIVE_STATE_CHANGE_TIMESTAMP_CONTEXT]);

        return $this;
    }
}
