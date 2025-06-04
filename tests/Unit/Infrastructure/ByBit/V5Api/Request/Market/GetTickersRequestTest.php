<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Market;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest
 */
final class GetTickersRequestTest extends TestCase
{
    public function testCreateGetTickersRequest(): void
    {
        $request = new GetTickersRequest($category = AssetCategory::linear, $symbol = SymbolEnum::BTCUSDT);

        self::assertSame('/v5/market/tickers', $request->url());
        self::assertSame(Request::METHOD_GET, $request->method());
        self::assertFalse($request->isPrivateRequest());
        self::assertSame(['category' => $category->value, 'symbol' => $symbol->name()], $request->data());
    }
}
