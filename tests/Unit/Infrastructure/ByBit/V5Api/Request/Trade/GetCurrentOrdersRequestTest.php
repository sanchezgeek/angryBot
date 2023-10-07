<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Trade;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
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
            $symbol = Symbol::BTCUSDT,
        );

        self::assertSame('/v5/order/realtime', $request->url());
        self::assertSame(Request::METHOD_GET, $request->method());
        self::assertSame([
            'category' => $category->value,
            'symbol' => $symbol->value,
            'openOnly' => '0',
        ], $request->data());
    }
}
