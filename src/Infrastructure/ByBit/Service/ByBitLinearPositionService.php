<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Helper\VolumeHelper;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\BadApiResponseException;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\Common\Result\ApiErrorInterface;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use App\Infrastructure\ByBit\Service\Exception\Trade\CannotAffordOrderCost;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\Trade\TickerOverConditionalOrderTriggerPrice;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use function is_array;
use function preg_match;
use function sprintf;

/**
 * @todo | now only for `linear` AssetCategory
 */
final class ByBitLinearPositionService implements PositionServiceInterface
{
    use ByBitApiCallHandler;

    private const ASSET_CATEGORY = AssetCategory::linear;

    public function __construct(ByBitApiClientInterface $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\GetPositionTest
     */
    public function getPosition(Symbol $symbol, Side $side): ?Position
    {
        $request = new GetPositionsRequest(self::ASSET_CATEGORY, $symbol);

        $data = $this->sendRequest($request)->data();
        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        $position = null;
        foreach ($list as $item) {
            if ((float)$item['avgPrice'] !== 0.0 && \strtolower($item['side']) === $side->value) {
                $position = new Position(
                    $side,
                    $symbol,
                    VolumeHelper::round((float)$item['avgPrice'], 2), // @todo | apiV5 | research `entryPrice` param
                    (float)$item['size'],
                    VolumeHelper::round((float)$item['positionValue'], 2),
                    (float)$item['liqPrice'],
                    (float)$item['positionIM'],
                    (int)$item['leverage'],
                    (float)$item['unrealisedPnl'],
                );
            }
        }

        return $position;
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
     */
    public function getOppositePosition(Position $position): ?Position
    {
        return $this->getPosition($position->symbol, $position->side->getOpposite());
    }

    /**
     * @inheritDoc
     *
     * @throws MaxActiveCondOrdersQntReached
     * @throws TickerOverConditionalOrderTriggerPrice
     *
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\AddStopTest
     */
    public function addStop(Position $position, Ticker $ticker, float $price, float $qty): string
    {
        $request = PlaceOrderRequest::stopConditionalOrderTriggeredByIndexPrice(
            self::ASSET_CATEGORY,
            $position->symbol,
            $position->side,
            $qty,
            $price
        );

        $result = $this->sendRequest($request, static function (ApiErrorInterface $error) use ($position) {
            $code = $error->code();
            $msg = $error->msg();

            if ($code === ApiV5Errors::MaxActiveCondOrdersQntReached->value) {
                throw new MaxActiveCondOrdersQntReached($msg);
            }

            if (
                $code === ApiV5Errors::BadRequestParams->value
                && preg_match(
                    sprintf(
                        '/expect %s, but trigger_price\[\d+\] %s current\[\d+\]/',
                        $position->isShort() ? 'Rising' : 'Falling',
                        $position->isShort() ? '<=' : '>='
                    ),
                    $msg
                )
            ) {
                throw new TickerOverConditionalOrderTriggerPrice($msg);
            }
        });

        $stopOrderId = $result->data()['orderId'] ?? null;
        if (!$stopOrderId) {
            throw BadApiResponseException::cannotFindKey($request, 'result.`orderId`', __METHOD__);
        }

        return $stopOrderId;
    }

    /**
     * @inheritDoc
     *
     * @throws CannotAffordOrderCost
     *
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\AddBuyOrderTest
     */
    public function marketBuy(Position $position, Ticker $ticker, float $price, float $qty): string
    {
        $request = PlaceOrderRequest::marketBuy(self::ASSET_CATEGORY, $position->symbol, $position->side, $qty);

        $result = $this->sendRequest($request, static function(ApiErrorInterface $error) use ($position, $qty) {
            match ($error->code()) {
                ApiV5Errors::CannotAffordOrderCost->value => throw CannotAffordOrderCost::forBuy(
                    $position->symbol,
                    $position->side,
                    $qty
                ),
                default => null
            };
        });

        $buyOrderId = $result->data()['orderId'] ?? null;
        if (!$buyOrderId) {
            throw BadApiResponseException::cannotFindKey($request, 'result.`orderId`', __METHOD__);
        }

        return $buyOrderId;
    }
}
