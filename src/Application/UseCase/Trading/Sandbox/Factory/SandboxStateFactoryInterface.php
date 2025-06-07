<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Factory;

use App\Application\UseCase\Trading\Sandbox\SandboxStateInterface;
use App\Trading\Domain\Symbol\SymbolInterface;

interface SandboxStateFactoryInterface
{
    public function byCurrentTradingAccountState(SymbolInterface $symbol): SandboxStateInterface;
}
