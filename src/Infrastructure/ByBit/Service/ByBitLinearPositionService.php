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

use LogicException;

use function end;
use function in_array;
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
        $positions = $this->getPositions($symbol);

        foreach ($positions as $position) {
            if ($position->side === $side) {
                return $position;
            }
        }

        return null;
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
     */
    public function getPositions(Symbol $symbol): array
    {
        $request = new GetPositionsRequest(self::ASSET_CATEGORY, $symbol);

        $data = $this->sendRequest($request)->data();
        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        /** @var Position[] $positions */
        $positions = [];
        foreach ($list as $item) {
            $opposite = null;
            if ((float)$item['avgPrice'] !== 0.0) {
                if ($positions) {
                    $opposite = end($positions);
                }
                $position = $this->parsePositionFromData($item);
                if (isset($opposite)) {
                    $position->setOppositePosition($opposite);
                    $opposite->setOppositePosition($position);
                }
                $positions[] = $position;
            }
        }

        if (count($positions) > 2) {
            throw new LogicException('More than two positions found on one symbol');
        }

        return $positions;
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
                in_array($code, [ApiV5Errors::BadRequestParams->value, ApiV5Errors::BadRequestParams2->value], true)
                && preg_match(
                    sprintf(
                        '/expect %s, but trigger_price\[\d+\] %s current\[\d+\]/',
                        $position->isShort() ? 'Rising' : 'Falling', // @todo ckeck
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
}
