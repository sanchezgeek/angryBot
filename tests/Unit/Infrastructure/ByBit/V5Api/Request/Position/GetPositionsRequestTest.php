<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Position;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest
 */
final class GetPositionsRequestTest extends TestCase
{
    public function testCreateGetPositionsRequest(): void
    {
        $request = new GetPositionsRequest($category = AssetCategory::linear, $symbol = Symbol::BTCUSDT);

        self::assertSame('/v5/position/list', $request->url());
        self::assertSame(Request::METHOD_GET, $request->method());
        self::assertTrue($request->isPrivateRequest());
        self::assertSame(['category' => $category->value, 'symbol' => $symbol->value], $request->data());
    }
}
