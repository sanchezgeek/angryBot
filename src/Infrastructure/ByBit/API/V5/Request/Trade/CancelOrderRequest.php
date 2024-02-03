<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Trade;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function sprintf;
use function strlen;

/**
 * @see https://bybit-exchange.github.io/docs/v5/order/cancel-order
 *
 * @see \App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Trade\CancelOrderRequestTest
 */
final readonly class CancelOrderRequest extends AbstractByBitApiRequest
{
    public const URL = '/v5/order/cancel';

    public function method(): string
    {
        return Request::METHOD_POST;
    }

    public function url(): string
    {
        return self::URL;
    }

    public static function byOrderId(
        AssetCategory $category,
        Symbol $symbol,
        string $orderId
    ): self {
        return new self($category, $symbol, $orderId);
    }

    public function data(): array
    {
        return [
            'category' => $this->category->value,
            'symbol' => $this->symbol->value,
            'orderId' => $this->oderId,
            'orderLinkId' => $this->oderLinkId,
        ];
    }

    private function __construct(
        private AssetCategory $category,
        private Symbol $symbol,
        private string $oderId,
        private ?string $oderLinkId = null,
    ) {
        assert(strlen($this->oderId), new InvalidArgumentException(
            sprintf('%s: $oderId must be non empty string (`%s` provided)', __CLASS__, $this->oderId)
        ));
    }
}
