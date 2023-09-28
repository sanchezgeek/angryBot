<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Market;

use App\Infrastructure\ByBit\V5Api\Request\Market\GetTickersRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \App\Infrastructure\ByBit\V5Api\Request\Market\GetTickersRequest
 */
final class GetTickersRequestTest extends TestCase
{
    public function testGetTickersRequest(): void
    {
        $request = new GetTickersRequest(
            $category = 'linear',
            $symbol = 'BTCUSDT'
        );

        self::assertSame('/v5/market/tickers', $request->url());
        self::assertSame(Request::METHOD_GET, $request->method());
        self::assertSame(['category' => $category, 'symbol' => $symbol], $request->data());
    }
}
