<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Kline;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see \App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Market\GetTickersRequestTest
 *
 * @link https://bybit-exchange.github.io/docs/v5/market/kline
 */
final readonly class GetKlinesRequest extends AbstractByBitApiRequest
{
    public const string URL = '/v5/market/kline';

    private const array MINUTES_DEF = [
        CandleIntervalEnum::m1->value => '1',
        CandleIntervalEnum::m5->value => '5',
        CandleIntervalEnum::m15->value => '15',
        CandleIntervalEnum::m30->value => '30',
        CandleIntervalEnum::h1->value => '60',
        CandleIntervalEnum::h2->value => '120',
        CandleIntervalEnum::h3->value => '180',
        CandleIntervalEnum::h4->value => '240',
        CandleIntervalEnum::h6->value => '360',
        CandleIntervalEnum::h12->value => '720',
        CandleIntervalEnum::D1->value => 'D',
        CandleIntervalEnum::W1->value => 'W',
        CandleIntervalEnum::M1->value => 'M',
    ];

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
            'symbol' => $this->symbol->name(),
            'interval' => self::MINUTES_DEF[$this->interval->value],
            'start' => $this->from->getTimestamp() * 1000,
        ];

        if ($this->to) {
            $data['to'] = $this->to->getTimestamp() * 1000;
        }

        if ($this->limit !== null) {
            $data['limit'] = $this->limit;
        }

        return $data;
    }

    public function __construct(
        private AssetCategory $category,
        private SymbolInterface $symbol,
        private CandleIntervalEnum $interval,
        private DateTimeImmutable $from,
        private ?DateTimeImmutable $to,
        private ?int $limit = null
    ) {
    }
}
