<?php

declare(strict_types=1);

namespace App\Tests\Assertion;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Trading\Domain\Symbol\SymbolInterface;
use BackedEnum;
use Exception;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExpectationFailedException;
use ReflectionClass;
use ReflectionUnionType;
use Throwable;

class CustomAssertions extends Assert
{
    private static function assertObjectsWithInnerSymbolsEquality(object $expected, object $actual, $message): void
    {
        self::assertIsObject($expected, $message);
        self::assertIsObject($actual, $message);

        $actual = self::prepareObjectWithInnerSymbol($actual);
        $expected = self::prepareObjectWithInnerSymbol($expected);

        self::assertEquals($expected, $actual);
    }

    public static function assertObjectsWithInnerSymbolsEquals($expected, $actual, $message = ''): void
    {
        if (is_array($expected)) {
            self::assertIsArray($actual);

            foreach ($expected as $key => $item) {
                $expected[$key] = self::prepareObjectWithInnerSymbol($item);
            }

            foreach ($actual as $key => $item) {
                $actual[$key] = self::prepareObjectWithInnerSymbol($item);
            }

            self::assertEquals($expected, $actual);
        } else {
            self::assertObjectsWithInnerSymbolsEquality($expected, $actual, $message);
        }
    }

    private static function prepareObjectWithInnerSymbol(object $object): object
    {
        if ($object instanceof SymbolInterface) {
            return SymbolEnum::from($object->name());
        }

        $ref = new ReflectionClass($object);

        $new = $ref->newInstanceWithoutConstructor();
        foreach ($ref->getProperties() as $propertyRef) {
            $oldValue = $propertyRef->getValue($object);

            $reflectionIntersectionType = $propertyRef->getType();
            if ($reflectionIntersectionType instanceof ReflectionUnionType) {
                $value = $oldValue;
            } elseif (SymbolInterface::class === $reflectionIntersectionType->getName()) {
                /** @var SymbolInterface $value */
                $value = SymbolEnum::from($oldValue->name());
            } elseif (is_object($oldValue) && !is_subclass_of($reflectionIntersectionType->getName(), BackedEnum::class)) {
                $value = self::prepareObjectWithInnerSymbol($oldValue);
            } else {
                $value = $oldValue;
            }

            $propertyRef->setValue($new, $value);
        }

        return $new;
    }
}
