<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Trade;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Trade\GetCurrentOrdersRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \App\Infrastructure\ByBit\API\V5\Request\Trade\GetCurrentOrdersRequest
 */
final class GetCurrentOrdersRequestTest extends TestCase
{
    public function testCreateGetOnlyOpenCurrentOrdersRequest(): void
    {
        $request = GetCurrentOrdersRequest::openOnly(
            $category = AssetCategory::linear,
            $symbol = SymbolEnum::BTCUSDT,
        );

        self::assertSame('/v5/order/realtime', $request->url());
        self::assertSame(Request::METHOD_GET, $request->method());
        self::assertTrue($request->isPrivateRequest());
        self::assertSame([
            'category' => $category->value,
            'openOnly' => '0',
            'symbol' => $symbol->name(),
        ], $request->data());
    }

    public function testCreateGetOnlyOpenCurrentOrdersRequestWithoutSymbol(): void
    {
        $request = GetCurrentOrdersRequest::openOnly(
            $category = AssetCategory::linear,
            null
        );

        self::assertSame('/v5/order/realtime', $request->url());
        self::assertSame(Request::METHOD_GET, $request->method());
        self::assertTrue($request->isPrivateRequest());
        self::assertSame([
            'category' => $category->value,
            'openOnly' => '0',
            'settleCoin' => 'USDT',
        ], $request->data());
    }
}
