<?php

declare(strict_types=1);

namespace App\Tests\Assertion;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Trading\Domain\Symbol\SymbolInterface;
use BackedEnum;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExpectationFailedException;
use ReflectionClass;

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
            foreach ($expected as $key => $expectedItem) {
                $actualItem = $actual[$key] ?? null;
                if ($expectedItem !== null && !$actualItem) {
                    throw new ExpectationFailedException('Failed to find corresponded item in $actual array');
                }

                if (!isset($expectedItem) && !isset($actualItem)) {
                    continue;
                }

                self::assertObjectsWithInnerSymbolsEquality($expectedItem, $actualItem, $message);
            }
        } else {
            self::assertObjectsWithInnerSymbolsEquality($expected, $actual, $message);
        }
    }

    private static function prepareObjectWithInnerSymbol(object $object): object
    {
        $ref = new ReflectionClass($object);

        $new = $ref->newInstanceWithoutConstructor();
        foreach ($ref->getProperties() as $propertyRef) {
            $oldValue = $propertyRef->getValue($object);

            if (SymbolInterface::class === $propertyRef->getType()->getName()) {
                /** @var SymbolInterface $value */
                $value = SymbolEnum::from($oldValue->name());
            } elseif (is_object($oldValue) && !is_subclass_of($propertyRef->getType()->getName(), BackedEnum::class)) {
                $value = self::prepareObjectWithInnerSymbol($oldValue);
            } else {
                $value = $oldValue;
            }

            $propertyRef->setValue($new, $value);
        }

        return $new;
    }
}
