<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Trading\SDK\Check\Dto;

use App\Trading\SDK\Check\Dto\TradingCheckResult;
use App\Trading\SDK\Check\Result\CommonOrderCheckFailureEnum;
use PHPUnit\Framework\TestCase;

final class TradingCheckResultTest extends TestCase
{
    public function testClone(): void
    {
        $prevResult = TradingCheckResult::failed('som-class', CommonOrderCheckFailureEnum::UnexpectedSandboxException, 'some-reason');
        $clone = $prevResult->quietClone();

        self::assertEquals($prevResult->success, $clone->success);
        self::assertEquals($prevResult->source, $clone->source);
        self::assertEquals($prevResult->failedReason, $clone->failedReason);
        self::assertTrue($clone->quiet);
    }
}
