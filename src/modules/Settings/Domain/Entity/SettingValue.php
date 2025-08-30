<?php

namespace App\Settings\Domain\Entity;

use App\Domain\Position\ValueObject\Side;
use App\Settings\Domain\Repository\SettingValueRepository;
use App\Trading\Domain\Symbol\Entity\Symbol;
use App\Trading\Domain\Symbol\SymbolInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SettingValueRepository::class)]
#[ORM\UniqueConstraint(columns: ['key', 'symbol', 'position_side'])]
class SettingValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', nullable: false)]
    public string $key;

    #[ORM\ManyToOne(targetEntity: Symbol::class, fetch: 'EAGER')]
    #[ORM\JoinColumn(name: 'symbol', referencedColumnName: 'name', nullable: true)]
    public ?SymbolInterface $symbol = null;

    #[ORM\Column(type: 'string', nullable: true, enumType: Side::class)]
    public ?Side $positionSide = null;

    #[ORM\Column(type: 'json', nullable: true)]
    public mixed $value = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    private function __construct(
        string $key,
        mixed $value = null,
        ?SymbolInterface $symbol = null,
        ?Side $positionSide = null,
    ) {
        $this->key = $key;
        $this->symbol = $symbol;
        $this->positionSide = $positionSide;
        $this->value = $value;
    }

    public static function withValue(string $key, mixed $value, ?SymbolInterface $symbol = null, ?Side $positionSide = null): self
    {
        return new self($key, $value, $symbol, $positionSide);
    }

    public static function disabled(string $key, ?SymbolInterface $symbol = null, ?Side $positionSide = null): self
    {
        return new self($key, null, $symbol, $positionSide);
    }

    public function disable(): self
    {
        $this->value = null;

        return $this;
    }

    public function isDisabled(): bool
    {
        return $this->value === null;
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'symbol' => $this->symbol?->name(),
            'positionSide' => $this->positionSide?->value,
            'value' => $this->value,
        ];
    }
}
