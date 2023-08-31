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
use App\Domain\Position\ValueObject\Side;
use App\Domain\Stop\Helper\PnlHelper;
use App\EventBus\HasEvents;
use App\EventBus\RecordEvents;
use Doctrine\ORM\Mapping as ORM;

/**
 * @see \App\Tests\Unit\Domain\Stop\StopTest
 */
#[ORM\Entity(repositoryClass: StopRepository::class)]
class Stop implements HasEvents
{
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

    public function getPnlInPercents(Position $position): float
    {
        return PnlHelper::getPnlInPercents($position, $this->price);
    }

    public function getPnlUsd(Position $position): float
    {
        $pnl = $this->getPnlInPercents($position) / 100;

        $positionPart = $this->volume / $position->size;
        $orderCost = $position->positionMargin * $positionPart;

        return $orderCost * $pnl;
    }
}
