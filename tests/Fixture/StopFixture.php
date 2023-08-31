<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Bot\Domain\Entity\Stop;
use App\Tests\Factory\Entity\StopBuilder;

final class StopFixture extends AbstractDoctrineFixture
{
    public function __construct(private readonly StopBuilder $builder)
    {
    }

    protected function buildEntity(): Stop
    {
        return $this->builder->build();
    }
}
