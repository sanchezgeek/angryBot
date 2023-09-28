<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\ByBit\V5Api\Request;

use App\Infrastructure\ByBit\V5Api\Request\GetPositionRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class GetPositionRequestTest extends TestCase
{
    public function testRequest(): void
    {
        $request = new GetPositionRequest(
            $category = 'linear',
            $symbol = 'BTCUSDT'
        );

        self::assertSame('/v5/position/list', $request->url());
        self::assertSame(Request::METHOD_GET, $request->method());
        self::assertSame(['category' => $category, 'symbol' => $symbol], $request->data());
    }
}
