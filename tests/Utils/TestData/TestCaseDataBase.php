<?php

declare(strict_types=1);

namespace App\Tests\Utils\TestData;

use App\Domain\Position\ValueObject\Side;
use RuntimeException;
use Stringable;
use Throwable;
use UnitEnum;

use function assert;
use function implode;
use function sprintf;
use function str_replace;

class TestCaseDataBase
{
    public function __construct(protected array $data, private ?string $name = null)
    {
    }

    public function with(array $data, string $appendName = null, $format = '%s | ..'): self
    {
        $clone = clone $this;

        $format = str_replace('..', '%s', $format);

        if ($appendName) {
            $clone->name = sprintf($format, $appendName, $clone->name);
        }

        $clone->data = array_merge($clone->data, $data);

        return $clone;
    }

    public function data(): array
    {
        return $this->data;
    }

    public function name(): ?string
    {
        $parts = [];
        foreach ($this->data as $key => $item) {
            if (
                $item instanceof Throwable
                || !($item instanceof Stringable || $item instanceof UnitEnum)
            ) {
                continue;
            }

            if ($item instanceof UnitEnum) {
                $item = $item->name;
            }

            $parts[] = sprintf('`%s` = \'%s\'', $key, $item);
        }

        $parts = $parts ? implode(', ', $parts) : null;

        return $this->name
            ? sprintf('%s%s', $this->name, $parts ? sprintf(' [%s]', $parts) : '')
            : $parts
        ;
    }

    public function withPositionSide(Side $positionSide): self
    {
        $clone = clone $this;

        $clone->with(['positionSide' => $positionSide]);

        return $clone;
    }

//    public function positionSide(): Side
//    {
//        assert(isset($this->data['positionSide']), $this->dataNotSetException('positionSide'));
//
//        return $this->data['positionSide'];
//    }

    public function __call(string $name, array $arguments)
    {
        $propertyName = $name;

        assert(isset($this->data[$propertyName]), $this->dataNotSetException($propertyName));

        return $this->data[$propertyName];
    }

    protected function dataNotSetException(string $key): Throwable
    {
        return new RuntimeException(sprintf('%s: `%s` key is not set', static::class, $key));
    }
}
