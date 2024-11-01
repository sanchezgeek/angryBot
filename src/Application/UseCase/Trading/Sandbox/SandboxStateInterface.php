<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox;

use App\Application\UseCase\Trading\Sandbox\Dto\ClosedPosition;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;

interface SandboxStateInterface
{
    public function getSymbol(): Symbol;
    public function getPositions(): array;
    public function getPosition(Side $side): ?Position;
    public function getMainPosition(): ?Position;
    public function setPositionAndActualizeOpposite(Position|ClosedPosition $input): void;
    public function modifyFreeBalance(CoinAmount|float $amount): self;
    public function getFreeBalance(): CoinAmount;
    public function getAvailableBalance(): CoinAmount;
    public function setLastPrice(Price|float $price): self;
}
