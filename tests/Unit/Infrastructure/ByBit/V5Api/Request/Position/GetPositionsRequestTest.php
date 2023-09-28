<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Position;

use App\Infrastructure\ByBit\V5Api\Request\Position\GetPositionsRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \App\Infrastructure\ByBit\V5Api\Request\Position\GetPositionsRequest
 */
final class GetPositionsRequestTest extends TestCase
{
    public function testGetPositionsRequest(): void
    {
        $request = new GetPositionsRequest(
            $category = 'linear',
            $symbol = 'BTCUSDT'
        );

        self::assertSame('/v5/position/list', $request->url());
        self::assertSame(Request::METHOD_GET, $request->method());
        self::assertSame(['category' => $category, 'symbol' => $symbol], $request->data());
    }
}
