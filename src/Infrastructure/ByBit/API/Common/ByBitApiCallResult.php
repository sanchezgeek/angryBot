<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\Common;

use App\Infrastructure\ByBit\API\Common\Result\ApiErrorInterface;

use function sprintf;

final readonly class ByBitApiCallResult
{
    private function __construct(private ?ApiErrorInterface $error, private ?array $data)
    {
    }

    public static function ok(array $data): self
    {
        return new self(null, $data);
    }

    public static function err(ApiErrorInterface $error): self
    {
        return new self($error, null);
    }

    public function isSuccess(): bool
    {
        return !$this->error;
    }

    public function error(): ApiErrorInterface
    {
        if ($this->isSuccess()) {
            throw new \LogicException(sprintf('%s: this is success result!', __METHOD__));
        }

        return $this->error;
    }

    public function data(): array
    {
        if (!$this->isSuccess()) {
            throw new \LogicException(
                sprintf('%s: this is error result (%d | %s)!', __METHOD__, $this->error->code(), $this->error->msg())
            );
        }

        return $this->data;
    }
}
