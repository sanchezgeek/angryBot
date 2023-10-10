<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Helper\VolumeHelper;
use App\Infrastructure\ByBit\API\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Exception\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Error;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Infrastructure\ByBit\Service\Exception\CannotAffordOrderCost;
use RuntimeException;

use function sprintf;

/**
 * @todo | now only for `linear` AssetCategory
 */
final readonly class ByBitLinearPositionService implements PositionServiceInterface
{
    private const ASSET_CATEGORY = AssetCategory::linear;

    public function __construct(private ByBitApiClientInterface $apiClient)
    {
    }

    /**
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\GetPositionTest
     */
    public function getPosition(Symbol $symbol, Side $side): ?Position
    {
        $request = new GetPositionsRequest(self::ASSET_CATEGORY, $symbol);

        // @todo | check format layer + throw some exception
        $data = $this->apiClient->send($request)->data();

        $position = null;
        foreach ($data['list'] as $item) {
            if ($item['avgPrice'] !== 0 && \strtolower($item['side']) === $side->value) {
                $position = new Position(
                    $side,
                    $symbol,
                    VolumeHelper::round((float)$item['avgPrice'], 2), // @todo | apiV5 | research `entryPrice` param
                    (float)$item['size'],
                    VolumeHelper::round((float)$item['positionValue'], 2),
                    (float)$item['liqPrice'],
                    (float)$item['positionBalance'],
                    (float)$item['leverage'],
                );
            }
        }

        return $position;
    }

    public function getOppositePosition(Position $position): ?Position
    {
        return $this->getPosition($position->symbol, $position->side->getOpposite());
    }

    /**
     * @return ?string Created stop order id or NULL if creation failed
     * @throws MaxActiveCondOrdersQntReached|ApiRateLimitReached
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\AddStopTest
     */
    public function addStop(Position $position, Ticker $ticker, float $price, float $qty): ?string
    {
        $request = PlaceOrderRequest::stopConditionalOrderTriggeredByIndexPrice(
            self::ASSET_CATEGORY,
            $position->symbol,
            $position->side,
            $qty,
            $price
        );

        $result = $this->apiClient->send($request);

        if (!$result->isSuccess()) {
            throw match (($err = $result->error())) {
                ApiV5Error::ApiRateLimitReached => new ApiRateLimitReached(),
                ApiV5Error::MaxActiveCondOrdersQntReached => new MaxActiveCondOrdersQntReached(),
                default => new RuntimeException(
                    sprintf('%s | make `%s`: unknown err code %d (%s)', __METHOD__, $request->url(), $err->code(), $err->desc())
                )
            };
        }

        return $result->data()['orderId'];
    }

    /**
     * @return ?string Created stop order id or NULL if creation failed
     * @throws MaxActiveCondOrdersQntReached|CannotAffordOrderCost|ApiRateLimitReached
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\AddBuyOrderTest
     */
    public function addBuyOrder(Position $position, Ticker $ticker, float $price, float $qty): ?string
    {
        $request = PlaceOrderRequest::marketOrder(
            self::ASSET_CATEGORY,
            $position->symbol,
            $position->side,
            $qty,
        );

        $result = $this->apiClient->send($request);

        if (!$result->isSuccess()) {
            throw match (($err = $result->error())) {
                ApiV5Error::ApiRateLimitReached => new ApiRateLimitReached(),
                ApiV5Error::CannotAffordOrderCost => CannotAffordOrderCost::forBuy($position->symbol, $position->side, $qty),
                ApiV5Error::MaxActiveCondOrdersQntReached => new MaxActiveCondOrdersQntReached(),
                default => new RuntimeException(
                    sprintf('%s | make `%s`: unknown err code %d (%s)', __METHOD__, $request->url(), $err->code(), $err->desc())
                )
            };
        }

        return $result->data()['orderId'];
    }
}
