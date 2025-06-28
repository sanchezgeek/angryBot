<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Kline;

use App\Domain\Trading\Enum\TimeFrame;
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
        TimeFrame::m1->value => '1',
        TimeFrame::m5->value => '5',
        TimeFrame::m15->value => '15',
        TimeFrame::m30->value => '30',
        TimeFrame::h1->value => '60',
        TimeFrame::h2->value => '120',
        TimeFrame::h3->value => '180',
        TimeFrame::h4->value => '240',
        TimeFrame::h6->value => '360',
        TimeFrame::h12->value => '720',
        TimeFrame::D1->value => 'D',
        TimeFrame::W1->value => 'W',
        TimeFrame::M1->value => 'M',
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
        private TimeFrame $interval,
        private DateTimeImmutable $from,
        private ?DateTimeImmutable $to,
        private ?int $limit = null
    ) {
    }
}
