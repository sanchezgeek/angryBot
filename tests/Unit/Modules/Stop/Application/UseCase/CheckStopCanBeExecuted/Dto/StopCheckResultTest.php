<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto;

use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopCheckResult;
use PHPUnit\Framework\TestCase;

final class StopCheckResultTest extends TestCase
{
    public function testClone(): void
    {
        $prevResult = StopCheckResult::negative('som-class', 'some-reason');
        $clone = $prevResult->resetReason();

        self::assertEquals($prevResult->success, $clone->success);
        self::assertEquals($prevResult->source, $clone->source);
        self::assertNull($clone->reason);
    }
}
