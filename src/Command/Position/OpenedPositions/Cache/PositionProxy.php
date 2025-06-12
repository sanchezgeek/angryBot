<?php

declare(strict_types=1);

namespace App\Command\Position\OpenedPositions\Cache;

use App\Bot\Domain\Position;
use InvalidArgumentException;

final class PositionProxy
{
    private array $replacements = [];

    /**
     * @return string[]
     */
    public static function getAvailableReplacements(): array
    {
        return ['size', 'unrealizedPnl'];
    }

    public function __construct(
        private readonly Position $wrapped
    ) {
    }

    public function __call(string $name, array $arguments)
    {
        return call_user_func([$this->wrapped, $name], ...$arguments);
    }

    public function __get(string $name)
    {
        return $this->replacements[$name] ?? $this->wrapped->{$name};
    }

    public function replace(string $name, mixed $value): self
    {
        if (!in_array($name, self::getAvailableReplacements(), true)) {
            throw new InvalidArgumentException(sprintf('Cannot process "%s" replacement', $name));
        }

        $this->replacements[$name] = $value;

        return $this;
    }

    public function hasChangesWith(PositionProxy $other): bool
    {
        return array_any($this->replacements, static fn($value, $name) => $value !== $other->{$name});
    }
}
