<?php

declare(strict_types=1);

namespace App\Tests\Mock\Response\ByBit;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\Result\ApiErrorInterface;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Tests\Mock\Response\MockResponseFactoryTrait;
use App\Tests\Mock\Response\ResponseBuilderInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_replace;
use function array_replace_recursive;

final class MarketResponseBuilder implements ResponseBuilderInterface
{
    use MockResponseFactoryTrait;

    public const ROOT_BODY_ARRAY = [
        "retCode" => 0,
        "retMsg" => "OK",
        "result" => [
            "category" => "inverse",
            "list" => []
        ],
        "retExtInfo" => [],
        "time" => 1672376496682
    ];

    public const TICKERS_LIST_ITEM = [
        'symbol' => 'BTCUSD',
        'lastPrice' => '16597.00',
        'indexPrice' => '16598.54',
        'markPrice' => '16596.00',
        'prevPrice24h' => '16464.50',
        'price24hPcnt' => '0.008047',
        'highPrice24h' => '30912.50',
        'lowPrice24h' => '15700.00',
        'prevPrice1h' => '16595.50',
        'openInterest' => '373504107',
        'openInterestValue' => '22505.67',
        'turnover24h' => '2352.94950046',
        'volume24h' => '49337318',
        'fundingRate' => '-0.001034',
        'nextFundingTime' => '1672387200000',
        'predictedDeliveryPrice' => '',
        'basisRate' => '',
        'deliveryFeeRate' => '',
        'deliveryTime' => '0',
        'ask1Size' => '1',
        'bid1Price' => '16596.00',
        'ask1Price' => '16597.50',
        'bid1Size' => '1',
        'basis' => ''
    ];

//    private AssetCategory $category;
    private array $tickersListItems = [];
    private int $statusCode = 200;


    private function __construct(private readonly AssetCategory $category, private readonly ?ApiErrorInterface $error)
    {
    }

    public static function ok(AssetCategory $category): self
    {
        return new self($category, null);
    }

    public static function error(AssetCategory $category, ApiErrorInterface $error): self
    {
        return new self($category, $error);
    }

    public function withTicker(
        Symbol $symbol,
        float $indexPrice,
        ?float $lastPrice,
        ?float $markPrice,
    ): self {
        $lastPrice = $lastPrice ?? $indexPrice;
        $markPrice = $markPrice ?? $indexPrice;

        $this->tickersListItems[] = array_replace(self::TICKERS_LIST_ITEM, [
            'symbol' => $symbol->value,
            'lastPrice' => (string)$lastPrice,
            'markPrice' => (string)$markPrice,
            'indexPrice' => (string)$indexPrice,
        ]);

        return $this;
    }

    public function build(): MockResponse
    {
        $body = self::ROOT_BODY_ARRAY;

        if ($this->error) {
            $body = array_replace_recursive($body, [
                'retCode' => $this->error->code()
            ]);

            return self::make($this->statusCode, $body);
        }

        $body['result']['category'] = $this->category->value;

        foreach ($this->tickersListItems as $item) {
            $body['result']['list'][] = $item;
        }

        return self::make($this->statusCode, $body);
    }
}
