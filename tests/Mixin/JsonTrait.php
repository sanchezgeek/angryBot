<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Throwable;

trait JsonTrait
{
    /**
     * @param array<string, mixed> $data
     */
    protected static function jsonEncode(array $data): string
    {
        static $jsonEncoder;

        if (!$jsonEncoder) {
            $jsonEncoder = new JsonEncoder();
        }

        return $jsonEncoder->encode($data, '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonDecode(string $json): ?array
    {
        static $jsonEncoder;

        if (!$jsonEncoder) {
            $jsonEncoder = new JsonEncoder();
        }

        try {
            return $jsonEncoder->decode($json, '');
        } catch (Throwable) {
            return null;
        }
    }
}
