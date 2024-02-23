<?php

declare(strict_types=1);

namespace App\Tests\Mock\Response\ByBitV5Api;

use App\Bot\Domain\Ticker;
use App\Tests\Mock\Response\MockResponseFactoryTrait;
use App\Tests\Mock\Response\ResponseBuilderInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_replace;

final class TickersResponseBuilder implements ResponseBuilderInterface
{
    use MockResponseFactoryTrait;

    public const ROOT_BODY_ARRAY = [
        'retCode' => 0,
        'retMsg' => 'OK',
        'result' => [
            'category' => 'linear',
            'list' => [],
        ],
        'retExtInfo' => [
        ],
        'time' => 1684765770483,
    ];

    public const TICKERS_LIST_ITEM = [
        "symbol" => "BTCUSD",
        "lastPrice" => "16597.00",
        "indexPrice" => "16598.54",
        "markPrice" => "16596.00",
        "prevPrice24h" => "16464.50",
        "price24hPcnt" => "0.008047",
        "highPrice24h" => "30912.50",
        "lowPrice24h" => "15700.00",
        "prevPrice1h" => "16595.50",
        "openInterest" => "373504107",
        "openInterestValue" => "22505.67",
        "turnover24h" => "2352.94950046",
        "volume24h" => "49337318",
        "fundingRate" => "-0.001034",
        "nextFundingTime" => "1672387200000",
        "predictedDeliveryPrice" => "",
        "basisRate" => "",
        "deliveryFeeRate" => "",
        "deliveryTime" => "0",
        "ask1Size" => "1",
        "bid1Price" => "16596.00",
        "ask1Price" => "16597.50",
        "bid1Size" => "1",
        "basis" => "",
    ];

    private array $ordersListItems = [];

    private function __construct(private readonly Ticker $ticker)
    {
    }

    public static function ok(Ticker $ticker): self
    {
        return new self($ticker);
    }

    public function build(): MockResponse
    {
        $ticker = $this->ticker;
        $symbol = $ticker->symbol;

        $body = self::ROOT_BODY_ARRAY;
        $body['result']['category'] = $ticker->symbol->associatedCategory()->value;

        $body['result']['list'][] = array_replace(
            self::TICKERS_LIST_ITEM,
            [
                'symbol' => $symbol->value,
                'lastPrice' => $ticker->lastPrice->value(),
                'markPrice' => $ticker->markPrice->value(),
                'indexPrice' => $ticker->indexPrice->value(),
            ]
        );

        return self::make($body);
    }
}
