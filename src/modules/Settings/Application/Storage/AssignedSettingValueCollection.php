<?php

declare(strict_types=1);

namespace App\Settings\Application\Storage;

use App\Settings\Application\Storage\Dto\AssignedSettingValue;
use IteratorAggregate;
use Traversable;

/**
 * @template-implements IteratorAggregate<AssignedSettingValue>
 */
final readonly class AssignedSettingValueCollection implements IteratorAggregate
{
    private array $values;

    public function __construct(AssignedSettingValue ... $values)
    {
        $this->values = $values;
    }

    public function isSettingHasFallbackValue(): bool
    {
        foreach ($this->values as $value) {
            if (!$value->symbol && !$value->side) {
                return true;
            }
        }

        return false;
    }

    public function getIterator(): Traversable
    {
        foreach ($this->values as $value) {
            yield $value;
        }
    }

    /**
     * @return AssignedSettingValue[]
     */
    public function mapByFullKey(): array
    {
        $result = [];

        foreach ($this->values as $value) {
            $result[$value->fullKey] = $value;
        }

        return $result;
    }

}
