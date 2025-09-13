<?php

declare(strict_types=1);

namespace App\Trading\Domain\Symbol;

use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Domain\Price\SymbolPrice;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;

interface SymbolInterface
{
    public function eq(SymbolInterface $other): bool;
    public function name(): string;

    public function associatedCoin(): Coin;
    public function associatedCoinAmount(float $amount): CoinAmount;
    public function associatedCategory(): AssetCategory;

    public function minOrderQty(): float|int;
    public function minNotionalOrderValue(): float|int;
    public function contractSizePrecision(): ?int;

    public function minimalPriceMove(): float;
    public function makePrice(float $value): SymbolPrice;
    public function pricePrecision(): int;
    public function stopDefaultTriggerDelta(): float;

    public function roundVolume(float $volume): float;
    public function roundVolumeDown(float $volume): float;
    public function roundVolumeUp(float $volume): float;

    public function shortName(): string;
    public function veryShortName(): string;
}
