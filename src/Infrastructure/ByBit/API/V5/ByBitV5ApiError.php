<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5;

use App\Infrastructure\ByBit\API\Common\Result\ApiErrorInterface;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;

final readonly class ByBitV5ApiError implements ApiErrorInterface
{
    public static function knownError(ApiV5Errors $error, string $message): self
    {
        return new self($error->code(), $message);
    }

    /**
     * @internal Only for tests
     */
    public static function unknown(int $code, string $message): self
    {
        return new self($code, $message);
    }

    public function code(): int
    {
        return $this->code;
    }

    public function msg(): string
    {
        return $this->message;
    }

    private function __construct(private int $code, private string $message)
    {
    }

//    public function desc(): string {return $this->error->name;}
}
