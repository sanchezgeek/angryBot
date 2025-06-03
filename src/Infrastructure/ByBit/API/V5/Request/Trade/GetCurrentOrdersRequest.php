<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Trade;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
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

    public static function openOnly(AssetCategory $category, ?SymbolInterface $symbol): self
    {
        return new self($category, $symbol, self::OPEN_ONLY_PARAM_OPTION);
    }

    public function data(): array
    {
        $data = [
            'category' => $this->category->value,
            'openOnly' => $this->openOnlyParam,
        ];

        if ($this->symbol) {
            $data['symbol'] = $this->symbol->value;
        } else {
            $data['settleCoin'] = SymbolEnum::BTCUSDT->associatedCoin()->value;
        }

        return $data;
    }

    private function __construct(
        private AssetCategory $category,
        private ?SymbolInterface $symbol,
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
