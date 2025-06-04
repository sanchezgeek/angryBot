<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Factory;

use App\Application\UseCase\Trading\Sandbox\TradingSandboxInterface;
use App\Trading\Domain\Symbol\SymbolInterface;

interface TradingSandboxFactoryInterface
{
    public function empty(SymbolInterface $symbol, bool $debug = false): TradingSandboxInterface;

    public function byCurrentState(SymbolInterface $symbol, bool $debug = false): TradingSandboxInterface;
}
