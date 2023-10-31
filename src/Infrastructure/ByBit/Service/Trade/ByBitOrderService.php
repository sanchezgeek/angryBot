<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Trade;

use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Domain\Position;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\BadApiResponseException;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;

use function debug_backtrace;

final class ByBitOrderService implements OrderServiceInterface
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
     */
    public function closeByMarket(Position $position, float $qty): string
    {
        return $this->sendPlaceOrderRequest(
            PlaceOrderRequest::marketClose(self::ASSET_CATEGORY, $position->symbol, $position->side, $qty)
        );
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
     */
    public function addLimitTP(Position $position, float $qty, float $price): string
    {
        return $this->sendPlaceOrderRequest(
            PlaceOrderRequest::limitTP(self::ASSET_CATEGORY, $position->symbol, $position->side, $qty, $price)
        );
    }

    /**
     * @return string Placed `orderId`
     *
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
     */
    private function sendPlaceOrderRequest(AbstractByBitApiRequest $request): string
    {
        $orderId = $this->sendRequest(
            request: $request,
            calledServiceMethod: debug_backtrace()[1]['function']
        )->data()['orderId'] ?? null;

        if (!$orderId) {
            throw BadApiResponseException::cannotFindKey($request, 'result.`orderId`', __METHOD__);
        }

        return $orderId;
    }
}
