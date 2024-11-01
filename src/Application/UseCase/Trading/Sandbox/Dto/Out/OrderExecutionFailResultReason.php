<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Dto\Out;

use Exception;
use InvalidArgumentException;

final readonly class OrderExecutionFailResultReason
{
    public function __construct(
        public ?Exception $exception = null,
        public ?string $message = null,
    ) {
        if (!$this->exception && !$this->message) {
            throw new InvalidArgumentException('One of $exception or $message must be provided');
        }
    }

    public function getReason(): string
    {
        return $this->message ?? $this->exception->getMessage();
    }

    public function isExceptionOneOf(array $inherited): bool
    {
        foreach ($inherited as $inheritedItem) {
            if ($this->exception instanceof $inheritedItem) {
                return true;
            }
        }

        return false;
    }
}
