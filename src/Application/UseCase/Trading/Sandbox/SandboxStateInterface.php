<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox;

use App\Application\UseCase\Trading\Sandbox\Dto\ClosedPosition;
use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Domain\Position;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Trading\Domain\Symbol\SymbolInterface;

interface SandboxStateInterface
{
    public function getSymbol(): SymbolInterface;
    public function getPositions(): array;
    public function getPosition(Side $side): ?Position;
    public function getMainPosition(): ?Position;
    public function setPositionAndActualizeOpposite(Position|ClosedPosition $input): void;
    public function addFreeBalance(CoinAmount|float $amount): self;
    public function subFreeBalance(CoinAmount|float $amount): self;
    public function getContractBalance(): ContractBalance;
    public function setContractBalance(ContractBalance $contractBalance): self;
    public function getFreeBalance(): CoinAmount;
    public function getFundsAvailableForLiquidation(): CoinAmount;
    public function getAvailableBalance(): CoinAmount;
    public function setLastPrice(SymbolPrice|float $price): self;
}
