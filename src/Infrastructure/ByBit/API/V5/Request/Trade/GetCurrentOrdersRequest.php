<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Trade;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function implode;
use function sprintf;

/**
 * @see https://bybit-exchange.github.io/docs/v5/order/open-order
 *
 * @see \App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Trade\GetCurrentOrdersRequestTest
 */
final readonly class GetCurrentOrdersRequest extends AbstractByBitApiRequest
{
    private const OPEN_ONLY_PARAM_OPTION = '0';

    private const OPEN_ONLY_AVAILABLE_OPTIONS = [
        self::OPEN_ONLY_PARAM_OPTION => true
    ];

    public const URL = '/v5/order/realtime';

    public function method(): string
    {
        return Request::METHOD_GET;
    }

    public function url(): string
    {
        return self::URL;
    }

    public static function openOnly(AssetCategory $category, Symbol $symbol): self
    {
        return new self($category, $symbol, self::OPEN_ONLY_PARAM_OPTION);
    }

    public function data(): array
    {
        return [
            'category' => $this->category->value,
            'symbol' => $this->symbol->value,
            'openOnly' => $this->openOnlyParam,
        ];
    }

    private function __construct(
        private AssetCategory $category,
        private Symbol $symbol,
        private string $openOnlyParam
    ) {
        assert(
            isset(self::OPEN_ONLY_AVAILABLE_OPTIONS[$this->openOnlyParam]),
            new InvalidArgumentException(
                sprintf(
                    '%s: $openOnlyParam must be in [\'%s\'] (%s given)',
                    __CLASS__,
                    implode('\', \'', self::OPEN_ONLY_AVAILABLE_OPTIONS),
                    $this->openOnlyParam
                )
            )
        );
    }
}
