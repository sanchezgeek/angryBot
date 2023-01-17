<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use App\Tests\Fixture\AbstractFixture;
use App\Tests\Fixture\Fixtures;

trait DbFixtureTrait
{
    private ?Fixtures $fixtures = null;

    protected function fixtures(): Fixtures
    {
        return $this->fixtures ?: $this->fixtures = new Fixtures(fn () => static::getContainer());
    }

    protected function applyDbFixtures(AbstractFixture ...$fixtures): void
    {
        $collection = $this->fixtures();
        foreach ($fixtures as $fixture) {
            $collection->add($fixture);
        }

        $collection->apply();
    }

    /**
     * @after
     */
    protected function ensureNoFixtures(): void
    {
        $this->fixtures = null;
    }
}
