<?php

declare(strict_types=1);

namespace App\Tests\Mock\Response\ByBitV5Api;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Tests\Mock\Response\MockResponseFactoryTrait;
use App\Tests\Mock\Response\ResponseBuilderInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_replace;
use function ucfirst;

final class PositionResponseBuilder implements ResponseBuilderInterface
{
    use MockResponseFactoryTrait;

    public const ROOT_BODY_ARRAY = [
        'retCode' => 0,
        'retMsg' => 'OK',
        'result' => [
            'list' => [],
            'nextPageCursor' => '',
            'category' => 'inverse'
        ],
        'retExtInfo' => [],
        'time' => 1684767531904
    ];

    public const POSITIONS_LIST_ITEM = [
        'positionIdx' => 0,
        'riskId' => 1,
        'riskLimitValue' => '150',
        'symbol' => 'BTCUSD',
        'side' => 'Sell',
        'size' => '299',
        'avgPrice' => '30004.5006751',
        'positionValue' => '0.00996518',
        'tradeMode' => 0,
        'positionStatus' => 'Normal',
        'autoAddMargin' => 1,
        'adlRankIndicator' => 2,
        'leverage' => '10',
        'positionBalance' => '0.00100189',
        'markPrice' => '26926.00',
        'liqPrice' => '999999.00',
        'bustPrice' => '999999.00',
        'positionMM' => '0.0000015',
        'positionIM' => '0.00009965',
        'tpslMode' => 'Full',
        'takeProfit' => '0.00',
        'stopLoss' => '0.00',
        'trailingStop' => '0.00',
        'unrealisedPnl' => '0.00113932',
        'cumRealisedPnl' => '-0.00121275',
        'createdTime' => '1676538056258',
        'updatedTime' => '1684742400015',
        'seq' => 4688002127
    ];

    private AssetCategory $category;
    private array $positionsListItems = [];
    private int $statusCode = 200;

    public function __construct(AssetCategory $category)
    {
        $this->category = $category;
    }

    public function addPosition(
        Symbol $symbol,
        Side $positionSide,
        float $entryPrice,
        float $positionSize,
        float $positionValue,
        float $margin,
        int $leverage,
        float $liqPrice,
        float $unrealizedPnl
    ): self {
        // @todo | move ucfirst to enum
        $this->positionsListItems[] = array_replace(self::POSITIONS_LIST_ITEM, [
            'symbol' => $symbol->value,
            'side' => ucfirst($positionSide->value),
            'avgPrice' => (string)$entryPrice,
            'positionValue' => (string)$positionValue,
            'positionIM' => (string)$margin,
            'size' => (string)$positionSize,
            'liqPrice' => (string)$liqPrice,
            'leverage' => (string)$leverage,
            'unrealisedPnl' => (string)$unrealizedPnl,
        ]);

        return $this;
    }

    public function withCode(int $code): self
    {
        $this->statusCode = $code;

        return $this;
    }

    public function build(): MockResponse
    {
        $body = self::ROOT_BODY_ARRAY;
        $body['result']['category'] = $this->category->value;

        foreach ($this->positionsListItems as $item) {
            $body['result']['list'][] = $item;
        }

        return self::make($body, $this->statusCode);
    }
}
