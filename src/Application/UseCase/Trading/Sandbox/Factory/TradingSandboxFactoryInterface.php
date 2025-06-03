<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Factory;

use App\Application\UseCase\Trading\Sandbox\TradingSandboxInterface;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;

interface TradingSandboxFactoryInterface
{
    public function empty(SymbolInterface $symbol, bool $debug = false): TradingSandboxInterface;

    public function byCurrentState(SymbolInterface $symbol, bool $debug = false): TradingSandboxInterface;
}
