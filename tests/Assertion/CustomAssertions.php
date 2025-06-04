<?php

declare(strict_types=1);

namespace App\Tests\Assertion;

use App\Trading\Domain\Symbol\SymbolInterface;
use PHPUnit\Framework\Assert;

/**
 * @todo test
 */
class CustomAssertions extends Assert
{
    public static function assertEqualsWithInnerSymbols($expected, $actual, $message = ''): void
    {
        self::assertIsObject($expected, $message);
        self::assertIsObject($actual, $message);

        $expectedVars = get_object_vars($expected);
        $actualVars = get_object_vars($actual);

        foreach ($expectedVars as $key => $value) {
            if ($value instanceof SymbolInterface) {
                \assert($actualVars[$key] instanceof SymbolInterface);
                self::assertEquals($value->name(), $actualVars[$key]->name(), $message . " (property: $key)");
            } elseif (is_object($value)) {
                self::assertEqualsWithInnerSymbols($value, $actualVars[$key], $message . " (nested in property: $key)");
            } else {
                self:: assertEquals($value, $actualVars[$key], $message . " (property: $key)");
            }
        }
    }
}
