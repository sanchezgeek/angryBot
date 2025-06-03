<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Factory;

use App\Application\UseCase\Trading\Sandbox\SandboxStateInterface;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;

interface SandboxStateFactoryInterface
{
    public function byCurrentTradingAccountState(SymbolInterface $symbol): SandboxStateInterface;
}
