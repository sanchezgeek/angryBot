<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Bot\Domain\Entity\BuyOrder;
use App\Tests\Factory\Entity\BuyOrderBuilder;

final class BuyOrderFixture extends AbstractDoctrineFixture
{
    public function __construct(private readonly BuyOrderBuilder $builder)
    {
    }

    protected function buildEntity(): BuyOrder
    {
        return $this->builder->build();
    }
}
