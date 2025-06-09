<?php

declare(strict_types=1);

namespace App\Command\Position\OpenedPositions\Cache;

use App\Bot\Domain\Position;

/**
 * @property-read $size
 */
final class PositionProxy
{
    private array $replacements = [];

    public function __construct(
        private readonly Position $wrapped
    ) {
    }

    public function setSize(float $size): self
    {
        $this->replacements['size'] = $size;

        return $this;
    }

    public function __call(string $name, array $arguments)
    {
        return call_user_func([$this->wrapped, $name], ...$arguments);
    }

    public function __get(string $name)
    {
        return $this->replacements[$name] ?? $this->wrapped->{$name};
    }
}
