<?php

declare(strict_types=1);

namespace App\Tests\PHPUnit;

use Throwable;

final class Assertions
{
    public static function exceptionEquals(Throwable $expected, Throwable $actual, bool $strict = false): bool
    {
        return $strict ? $expected === $actual :  str_contains($actual->getMessage(), $expected->getMessage()) && get_class($expected) === get_class($actual);
    }

    public static function errorLogged(TestLogger $logger, string $message, string $level, array $context = null): bool
    {
        foreach ($logger->records as $record) {
            if (
                $record['message'] === $message
                && $record['level'] === $level
                && (
                    $context === null
                    || $record['context'] === $context
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
