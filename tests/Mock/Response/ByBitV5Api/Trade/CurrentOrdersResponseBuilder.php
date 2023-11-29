<?php

declare(strict_types=1);

namespace App\Tests\Mock\Response\ByBitV5Api\Trade;

use App\Bot\Domain\ValueObject\Order\ExecutionOrderType;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiError;
use App\Tests\Mock\Response\MockResponseFactoryTrait;
use App\Tests\Mock\Response\ResponseBuilderInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_replace;
use function array_replace_recursive;

final class CurrentOrdersResponseBuilder implements ResponseBuilderInterface
{
    use MockResponseFactoryTrait;

    public const ROOT_BODY_ARRAY = [
        'retCode' => 0,
        'retMsg' => 'OK',
        'result' => [
            'list' => [],
            'nextPageCursor' => 'page_args%3Dfd4300ae-7847-404e-b947-b46980a4d140%26symbol%3D6%26',
            'category' => 'linear'
        ],
        'retExtInfo' => [
        ],
        'time' => 1684765770483
    ];

    public const ORDERS_LIST_ITEM = [
        'orderId' => 'fd4300ae-7847-404e-b947-b46980a4d140',
        'orderLinkId' => 'test-000005',
        'blockTradeId' => '',
        'symbol' => 'ETHUSDT',
        'price' => '1600.00',
        'qty' => '0.10',
        'side' => 'Buy',
        'isLeverage' => '',
        'positionIdx' => 1,
        'orderStatus' => 'New',
        'cancelType' => 'UNKNOWN',
        'rejectReason' => 'EC_NoError',
        'avgPrice' => '0',
        'leavesQty' => '0.10',
        'leavesValue' => '160',
        'cumExecQty' => '0.00',
        'cumExecValue' => '0',
        'cumExecFee' => '0',
        'timeInForce' => 'GTC',
        'orderType' => 'Limit',
        'stopOrderType' => 'UNKNOWN',
        'orderIv' => '',
        'triggerPrice' => '0.00',
        'takeProfit' => '2500.00',
        'stopLoss' => '1500.00',
        'tpTriggerBy' => 'LastPrice',
        'slTriggerBy' => 'LastPrice',
        'triggerDirection' => 0,
        'triggerBy' => 'UNKNOWN',
        'lastPriceOnCreated' => '',
        'reduceOnly' => false,
        'closeOnTrigger' => false,
        'smpType' => 'None',
        'smpGroup' => 0,
        'smpOrderId' => '',
        'tpslMode' => 'Full',
        'tpLimitPrice' => '',
        'slLimitPrice' => '',
        'placeType' => '',
        'createdTime' => '1684738540559',
        'updatedTime' => '1684738540561'
    ];

    private array $ordersListItems = [];

    private function __construct(private readonly AssetCategory $category, private readonly ?ByBitV5ApiError $error)
    {
    }

    public static function ok(AssetCategory $category): self
    {
        return new self($category, null);
    }

    public static function error(AssetCategory $category, ByBitV5ApiError $error): self
    {
        return new self($category, $error);
    }

    public function withOrder(
        Symbol $symbol,
        Side $orderSide,
        string $orderId,
        float $triggerPrice,
        float $qty,
        TriggerBy $triggerBy,
        ExecutionOrderType $orderType,
        bool $reduceOnly,
        bool $closeOnTrigger,
    ): self {
        $this->ordersListItems[] = array_replace(self::ORDERS_LIST_ITEM, [
            'symbol' => $symbol->value,
            'side' => $orderSide->value,
            'orderId' => $orderId,
            'triggerPrice' => (string)$triggerPrice,
            'qty' => (string)$qty,
            'triggerBy' => $triggerBy->value,
            'orderType' => $orderType->value,
            'reduceOnly' => $reduceOnly,
            'closeOnTrigger' => $closeOnTrigger,
        ]);

        return $this;
    }

    public function build(): MockResponse
    {
        $body = self::ROOT_BODY_ARRAY;
        $body['result']['category'] = $this->category->value;

        if ($this->error) {
            $body = array_replace_recursive($body, [
                'retCode' => $this->error->code(),
                'retMsg' => $this->error->msg(),
            ]);

            return self::make($body);
        }

        foreach ($this->ordersListItems as $item) {
            $body['result']['list'][] = $item;
        }

        return self::make($body);
    }
}
