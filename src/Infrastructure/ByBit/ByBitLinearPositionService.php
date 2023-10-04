<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit;

// @todo | apiV5 | handle errors from ByBitApiCallResult and throw exceptions
use App\Bot\Application\Exception\ApiRateLimitReached;
use App\Bot\Application\Exception\CannotAffordOrderCost;
use App\Bot\Application\Exception\MaxActiveCondOrdersQntReached;

// @todo | apiV5 | add separated cached service
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Trade\PlaceOrderRequestTest;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Cache\CacheInterface;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Helper\VolumeHelper;
use App\Infrastructure\ByBit\API\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;

/**
 * @see ByBitLinearPositionServiceTest
 *
 * @todo | now only for `linear` AssetCategory
 */
final readonly class ByBitLinearPositionService implements PositionServiceInterface
{
    private const ASSET_CATEGORY = AssetCategory::linear;

    public function __construct(private ByBitApiClientInterface $apiClient)
    {
    }

    public function getPosition(Symbol $symbol, Side $side): ?Position
    {
        $request = new GetPositionsRequest(self::ASSET_CATEGORY, $symbol);

        $result = $this->apiClient->send($request);

        // @todo | check format layer + throw some exception

        $position = null;
        foreach ($result['list'] as $item) {
            if ($item['avgPrice'] !== 0 && \strtolower($item['side']) === $side->value) {
                $position = new Position(
                    $side,
                    $symbol,
                    VolumeHelper::round((float)$item['avgPrice'], 2), // @todo | apiV5 | research `entryPrice` param
                    (float)$item['size'],
                    VolumeHelper::round((float)$item['positionValue'], 2),
                    (float)$item['liqPrice'],
                    (float)$item['positionMM'], // @todo | apiV5 | research `margin` param
                    (float)$item['leverage'],
                );
            }
        }

        return $position;
    }

    public function getOppositePosition(Position $position): ?Position
    {
        // @todo | apiV5 | cache?
        return $this->getPosition($position->symbol, $position->side->getOpposite());
    }

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

        return $result['orderId'];
    }

    public function addBuyOrder(Position $position, Ticker $ticker, float $price, float $qty): ?string
    {
        $request = PlaceOrderRequest::buyOrderImmediatelyTriggeredByIndexPrice(
            self::ASSET_CATEGORY,
            $position->symbol,
            $position->side,
            $qty,
            $price
        );

        $result = $this->apiClient->send($request);

        return $result['orderId'];
    }
}
