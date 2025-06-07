<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Trading\Domain\Symbol\Entity\Symbol;

final class SymbolFixture extends AbstractDoctrineFixture
{
    public function __construct(private readonly Symbol $symbol)
    {
    }

    protected function getEntity(): Symbol
    {
        return $this->symbol;
    }
}
