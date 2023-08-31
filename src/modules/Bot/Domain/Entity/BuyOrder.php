<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity;

use App\Bot\Domain\Entity\Common\HasExchangeOrderContext;
use App\Bot\Domain\Entity\Common\HasVolume;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Ticker;
use App\Domain\Position\ValueObject\Side;
use App\EventBus\HasEvents;
use App\EventBus\RecordEvents;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BuyOrderRepository::class)]
class BuyOrder implements HasEvents
{
    public const WITH_SHORT_STOP_CONTEXT_NAME = 'withShortStop';

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

    public function mustBeExecuted(Ticker $ticker): bool
    {
        return $ticker->isIndexAlreadyOverBuyOrder($this->positionSide, $this->price);
    }

    public function isWithShortStop(): bool
    {
        return ($this->context[self::WITH_SHORT_STOP_CONTEXT_NAME] ?? null) === true;
    }
}
