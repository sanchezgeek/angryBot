<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
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
use function strtolower;

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
        $oppositePosition = null;
        foreach ($list as $item) {
            if ((float)$item['avgPrice'] !== 0.0) {
                $itemSide = strtolower($item['side']);
                if ($itemSide === $side->value) {
                    $position = $this->parsePositionFromData($item);
                } elseif ($itemSide === $side->getOpposite()->value) {
                    $oppositePosition = $this->parsePositionFromData($item);
                }
            }
        }

        if ($position && $oppositePosition) {
            $position->setOppositePosition($oppositePosition);
        }

        return $position;
    }

    private function parsePositionFromData(array $apiData): Position
    {
        return new Position(
            Side::from(strtolower($apiData['side'])),
            Symbol::from($apiData['symbol']),
            VolumeHelper::round((float)$apiData['avgPrice'], 2),
            (float)$apiData['size'],
            VolumeHelper::round((float)$apiData['positionValue'], 2),
            (float)$apiData['liqPrice'],
            (float)$apiData['positionIM'],
            (int)$apiData['leverage'],
            (float)$apiData['unrealisedPnl'],
        );
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
    public function addConditionalStop(Position $position, float $price, float $qty, TriggerBy $triggerBy): string
    {
        $request = PlaceOrderRequest::stopConditionalOrder(
            self::ASSET_CATEGORY,
            $position->symbol,
            $position->side,
            $qty,
            $price,
            $triggerBy,
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
                        $position->isShort() ? '<=' : '>=',
                    ),
                    $msg,
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
    public function marketBuy(Position $position, float $qty): string
    {
        $request = PlaceOrderRequest::marketBuy(self::ASSET_CATEGORY, $position->symbol, $position->side, $qty);

        $result = $this->sendRequest($request, static function (ApiErrorInterface $error) use ($position, $qty) {
            match ($error->code()) {
                ApiV5Errors::CannotAffordOrderCost->value => throw CannotAffordOrderCost::forBuy(
                    $position->symbol,
                    $position->side,
                    $qty,
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
