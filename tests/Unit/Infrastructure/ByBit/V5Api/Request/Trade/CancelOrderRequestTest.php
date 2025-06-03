<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Trade;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Trade\CancelOrderRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function uuid_create;

/**
 * @covers \App\Infrastructure\ByBit\API\V5\Request\Trade\CancelOrderRequest
 */
final class CancelOrderRequestTest extends TestCase
{
    public function testCreateWithOrderId(): void
    {
        $orderId = uuid_create();

        $request = CancelOrderRequest::byOrderId(
            $category = AssetCategory::linear,
            $symbol = SymbolEnum::BTCUSDT,
            $orderId,
        );

        self::assertSame('/v5/order/cancel', $request->url());
        self::assertSame(Request::METHOD_POST, $request->method());
        self::assertTrue($request->isPrivateRequest());
        self::assertSame([
            'category' => $category->value,
            'symbol' => $symbol->value,
            'orderId' => $orderId,
            'orderLinkId' => null,
        ], $request->data());
    }
}
