<?php

namespace App\Trading\Domain\Symbol\Entity;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Domain\Price\Exception\PriceCannotBeLessThanZero;
use App\Domain\Price\SymbolPrice;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Trading\Domain\Symbol\Helper\SymbolHelper;
use App\Trading\Domain\Symbol\Repository\SymbolRepository;
use App\Trading\Domain\Symbol\SymbolInterface;
use Doctrine\ORM\Mapping as ORM;
use Stringable;

/**
 * @todo | symbol | probably it must been also exchange name (so in mapped type you can select symbols based on this value)
 */
#[ORM\Entity(repositoryClass: SymbolRepository::class)]
#[ORM\Cache(region: 'append_only')]
class Symbol implements SymbolInterface, Stringable
{
    #[ORM\Id]
    #[ORM\Column(length: 50)]
    private string $name;

    #[ORM\Column(length: 10)]
    private Coin $associatedCoin;

    #[ORM\Column(length: 10)]
    private AssetCategory $associatedCategory;

    #[ORM\Column]
    private float $minOrderQty;

    #[ORM\Column]
    private float $minNotionalOrderValue;

    #[ORM\Column]
    private int $pricePrecision;

    public function __construct(
        string $name,
        Coin $associatedCoin,
        AssetCategory $associatedCategory,
        float $minOrderQty,
        float $minNotionalOrderValue,
        int $pricePrecision,
    ) {
        $this->name = $name;
        $this->associatedCoin = $associatedCoin;
        $this->associatedCategory = $associatedCategory;
        $this->minOrderQty = $minOrderQty;
        $this->minNotionalOrderValue = $minNotionalOrderValue;
        $this->pricePrecision = $pricePrecision;
    }

    public function eq(SymbolInterface $other): bool
    {
        return $this->name === $other->name();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function associatedCoin(): Coin
    {
        return $this->associatedCoin;
    }

    public function contractSizePrecision(): ?int
    {
        return SymbolHelper::contractSizePrecision($this);
    }

    public function associatedCoinAmount(float $amount): CoinAmount
    {
        return new CoinAmount($this->associatedCoin(), $amount);
    }

    public function associatedCategory(): AssetCategory
    {
        return $this->associatedCategory;
    }

    public function minOrderQty(): float|int
    {
        return $this->minOrderQty;
    }

    public function minNotionalOrderValue(): float|int
    {
        return $this->minNotionalOrderValue;
    }

    public function minimalPriceMove(): float
    {
        return SymbolHelper::minimalPriceMove($this);
    }

    /**
     * @throws PriceCannotBeLessThanZero
     */
    public function makePrice(float $value): SymbolPrice
    {
        return SymbolPrice::create($value, $this);
    }

    public function pricePrecision(): int
    {
        return $this->pricePrecision;
    }

    public function stopDefaultTriggerDelta(): float
    {
        return SymbolEnum::STOP_TRIGGER_DELTA[$this->name] ?? SymbolHelper::stopDefaultTriggerDelta($this);
    }

    public function roundVolume(float $volume): float
    {
        return SymbolHelper::roundVolume($this, $volume);
    }

    public function roundVolumeDown(float $volume): float
    {
        return SymbolHelper::roundVolumeDown($this, $volume);
    }

    public function roundVolumeUp(float $volume): float
    {
        return SymbolHelper::roundVolumeUp($this, $volume);
    }

    public function shortName(): string
    {
        return str_replace($this->associatedCoin->value, '', $this->name);
    }

    public function veryShortName(): string
    {
        return substr(SymbolEnum::VERY_SHORT_NAMES[$this->name] ?? $this->shortName(), 0, 3);
    }

    public static function fromShortName(string $name): SymbolInterface
    {

    }

    public function __toString(): string
    {
        return $this->name;
    }
}
