<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

use App\Delivery\Domain\Event\DeliveryAddressChanged;
use App\EventBus\HasEvents;
use App\EventBus\RecordEvents;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeliveryRepository::class)]
final class Delivery implements HasEvents
{
    use RecordEvents;

    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(unique: true)]
    private int $orderId;

    #[ORM\Column]
    private ?string $address = null;

    #[ORM\Column(nullable: true)]
    private ?int $distance = null;

    #[ORM\Column(nullable: true)]
    private ?int $cost = null;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function setOrderId(int $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): self
    {
        if ($this->address !== $address) {
            $this->address = $address;
            $this->distance = null;
            $this->cost = null;

            $this->recordThat(new DeliveryAddressChanged($this->id));
        }

        return $this;
    }

    public function getDistance(): ?int
    {
        return $this->distance;
    }

    public function setDistance(?int $distance): self
    {
        $this->distance = $distance;

        return $this;
    }

    public function setCost(?int $cost): self
    {
        $this->cost = $cost;

        return $this;
    }
}
