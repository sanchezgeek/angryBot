<?php

declare(strict_types=1);

namespace App\Tests\Mock\Response\ByBit;

use App\Tests\Mock\Response\AbstractMockResponseFactory;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ByBitResponses extends AbstractMockResponseFactory
{
    public const SAMPLE_TICKERS_RESPONSE = [
        "retCode" => 0,
        "retMsg" => "OK",
        "result" => [
            "category" => "inverse",
            "list" => [
                [
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
                    "basis" => ""
                ]
            ]
        ],
        "retExtInfo" => [],
        "time" => 1672376496682
    ];

    public const SAMPLE_POSITIONS_RESPONSE = [
        "retCode" => 0,
        "retMsg" => "OK",
        "result" => [
            "list" => [
                [
                    "positionIdx" => 0,
                    "riskId" => 1,
                    "riskLimitValue" => "150",
                    "symbol" => "BTCUSD",
                    "side" => "Sell",
                    "size" => "299",
                    "avgPrice" => "30004.5006751",
                    "positionValue" => "0.00996518",
                    "tradeMode" => 0,
                    "positionStatus" => "Normal",
                    "autoAddMargin" => 1,
                    "adlRankIndicator" => 2,
                    "leverage" => "10",
                    "positionBalance" => "0.00100189",
                    "markPrice" => "26926.00",
                    "liqPrice" => "999999.00",
                    "bustPrice" => "999999.00",
                    "positionMM" => "0.0000015",
                    "positionIM" => "0.00009965",
                    "tpslMode" => "Full",
                    "takeProfit" => "0.00",
                    "stopLoss" => "0.00",
                    "trailingStop" => "0.00",
                    "unrealisedPnl" => "0.00113932",
                    "cumRealisedPnl" => "-0.00121275",
                    "createdTime" => "1676538056258",
                    "updatedTime" => "1684742400015",
                    "seq" => 4688002127
                ]
            ],
            "nextPageCursor" => "",
            "category" => "inverse"
        ],
        "retExtInfo" => [],
        "time" => 1684767531904
    ];

    public static function tickers(array $body = self::SAMPLE_TICKERS_RESPONSE): MockResponse
    {
        return self::make(200, $body);
    }

    public static function positions(array $body = self::SAMPLE_POSITIONS_RESPONSE): MockResponse
    {
        return self::make(200, $body);
    }
}
