<?php

namespace App\Settings\Domain\Entity;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Domain\Repository\SettingValueRepository;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

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

    #[ORM\Column(type: 'string', nullable: true, enumType: Symbol::class)]
    public ?Symbol $symbol = null;

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
        ?Symbol $symbol = null,
        ?Side $positionSide = null,
    ) {
        if ($this->positionSide && !$this->symbol) {
            throw new InvalidArgumentException('Symbol must be specified too when side specified');
        }

        $this->key = $key;
        $this->symbol = $symbol;
        $this->positionSide = $positionSide;
        $this->value = $value;
    }

    public static function withValue(string $key, mixed $value, ?Symbol $symbol = null, ?Side $positionSide = null): self
    {
        return new self($key, $value, $symbol, $positionSide);
    }

    public static function disabled(string $key, ?Symbol $symbol = null, ?Side $positionSide = null): self
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
}
