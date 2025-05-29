<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\Application\UniqueIdGeneratorInterface;

use function uniqid;

final readonly class UniqueIdGeneratorStub implements UniqueIdGeneratorInterface
{
    private string $uniqueId;

    public function __construct(?string $uniqueId = null)
    {
        $this->uniqueId = $uniqueId ?: uniqid('some-uniq.', true);
    }

    public function generateUniqueId(string $prefix): string
    {
        return $this->uniqueId;
    }

    public function getMockedUniqueId(): string
    {
        return $this->uniqueId;
    }
}
