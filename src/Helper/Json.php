<?php

declare(strict_types=1);

namespace App\Helper;

/**
 * Helper for JSON encoding and decoding with most useful defaults.
 */
final class Json
{
    /**
     * @throws \JsonException
     */
    public static function encode($value): string
    {
        return \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @throws \JsonException
     */
    public static function decode(string $json): array
    {
        return \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }

    public static function encodePretty($value): string
    {
        return \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
    }

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }
}
