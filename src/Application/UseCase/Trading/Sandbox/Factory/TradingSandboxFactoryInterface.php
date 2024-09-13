<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Factory;

use App\Application\UseCase\Trading\Sandbox\TradingSandboxInterface;
use App\Bot\Domain\ValueObject\Symbol;

interface TradingSandboxFactoryInterface
{
    public function empty(Symbol $symbol, bool $debug = false): TradingSandboxInterface;

    public function byCurrentState(Symbol $symbol, bool $debug = false): TradingSandboxInterface;
}
