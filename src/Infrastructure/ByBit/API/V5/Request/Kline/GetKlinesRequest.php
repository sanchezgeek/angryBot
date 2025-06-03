<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Kline;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see \App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Market\GetTickersRequestTest
 *
 * @link https://bybit-exchange.github.io/docs/v5/market/kline
 */
final readonly class GetKlinesRequest extends AbstractByBitApiRequest
{
    public const URL = '/v5/market/kline';

    public function method(): string
    {
        return Request::METHOD_GET;
    }

    public function url(): string
    {
        return self::URL;
    }

    public function isPrivateRequest(): bool
    {
        return false;
    }

    public function data(): array
    {
        $data = [
            'category' => $this->category->value,
            'symbol' => $this->symbol instanceof SymbolInterface ? $this->symbol->value : $this->symbol,
            'interval' => $this->interval,
        ];

        if ($this->from && $this->to) {
            $data['start'] = $this->from->getTimestamp() * 1000;
            $data['to'] = $this->to->getTimestamp() * 1000;
        }

        if ($this->limit !== null) {
            $data['limit'] = $this->limit;
        }

        return $data;
    }

    public function __construct(
        private AssetCategory $category,
        private SymbolInterface|string $symbol,
        private int $interval,
        private DateTimeImmutable $from,
        private DateTimeImmutable $to,
        private ?int $limit = null
    ) {
    }
}
