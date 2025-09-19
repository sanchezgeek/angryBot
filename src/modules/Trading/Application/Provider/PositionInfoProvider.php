<?php

declare(strict_types=1);

namespace App\Trading\Application\Provider;

use App\Bot\Domain\Position;
use App\Domain\Position\Helper\InitialMarginHelper;
use App\Domain\Value\Percent\Percent;
use App\Trading\Contract\ContractBalanceProviderInterface;
use App\Trading\Contract\PositionInfoProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class PositionInfoProvider implements PositionInfoProviderInterface
{
    public function __construct(
        private ContractBalanceProviderInterface $contractBalanceProvider
    ) {
    }

    public function getRealInitialMarginToTotalContractBalanceRatio(SymbolInterface $symbol, Position|float $position): Percent
    {
        $im = $position instanceof Position ? InitialMarginHelper::realInitialMargin($position) : $position;
        $contractBalance = $this->contractBalanceProvider->getContractWalletBalance($symbol->associatedCoin());

        return Percent::fromPart($im / $contractBalance->totalWithUnrealized()->value());
    }
}
