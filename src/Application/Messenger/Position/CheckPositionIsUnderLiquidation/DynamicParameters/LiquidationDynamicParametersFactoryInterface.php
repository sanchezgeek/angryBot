<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidation;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;

interface LiquidationDynamicParametersFactoryInterface
{
    public function create(
        CheckPositionIsUnderLiquidation $handledMessage,
        Position $position,
        Ticker $ticker,
    ): LiquidationDynamicParametersInterface;

    public function fakeWithoutHandledMessage(
        Position $position,
        Ticker $ticker,
    ): LiquidationDynamicParametersInterface;
}
