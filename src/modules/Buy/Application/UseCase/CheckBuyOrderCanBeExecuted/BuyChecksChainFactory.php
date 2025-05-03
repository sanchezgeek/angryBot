<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted;

use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\FurtherPositionLiquidationCheck\BuyAndCheckFurtherPositionLiquidation;

final readonly class BuyChecksChainFactory
{
    public function __construct(
        private BuyAndCheckFurtherPositionLiquidation $furtherPositionLiquidationCheck
    ) {
    }

    public function full(): BuyChecksChain
    {
        return new BuyChecksChain(
            $this->furtherPositionLiquidationCheck
        );
    }
}
