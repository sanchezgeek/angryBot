<?php

namespace App\Screener\Domain\Entity;

use App\Screener\Domain\Repository\SymbolPriceHistoryRepository;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SymbolPriceHistoryRepository::class)]
#[ORM\UniqueConstraint(columns: ['symbol', 'date_time'])]
class SymbolPriceHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Symbol::class, fetch: 'EAGER')]
    #[ORM\JoinColumn(name: 'symbol', referencedColumnName: 'name')]
    public SymbolInterface $symbol;

    #[ORM\Column(type: 'float')]
    public float $lastPrice;

    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $dateTime;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __construct(
        SymbolInterface $symbol,
        float $lastPrice,
        DateTimeImmutable $dateTimeImmutable
    ) {
        $this->symbol = $symbol;
        $this->lastPrice = $lastPrice;
        $this->dateTime = $dateTimeImmutable;
    }
}
