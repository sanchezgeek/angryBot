<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\ByBit\V5Api\Request;

use App\Infrastructure\ByBit\V5Api\Request\GetTickerRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class GetTickerV5ApiRequestTest extends TestCase
{
    public function testRequest(): void
    {
        $request = new GetTickerRequest(
            $category = 'linear',
            $symbol = 'BTCUSDT'
        );

        self::assertSame('/v5/market/tickers', $request->url());
        self::assertSame(Request::METHOD_GET, $request->method());
        self::assertSame(['category' => $category, 'symbol' => $symbol], $request->data());
    }
}
