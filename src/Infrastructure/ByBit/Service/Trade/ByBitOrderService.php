<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\Trade;

use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\BadApiResponseException;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\Common\Result\ApiErrorInterface;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\Cache\PositionsCache;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

use function debug_backtrace;
use function sprintf;

final class ByBitOrderService implements OrderServiceInterface
{
    use ByBitApiCallHandler;

    private const ASSET_CATEGORY = AssetCategory::linear;

    public function __construct(
        ByBitApiClientInterface $apiClient,
        private readonly PositionsCache $positionsCache,
        private ?LoggerInterface $appErrorLogger,
    ) {
        $this->apiClient = $apiClient;
    }

    /**
     * @internal For disable logging in tests
     */
    public function unsetLogger(): void
    {
        $this->appErrorLogger = null;
    }

    /**
     * @inheritDoc
     *
     * @throws CannotAffordOrderCostException
     *
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\Trade\ByBitOrderServiceTest\MarketBuyTest
     */
    public function marketBuy(Symbol $symbol, Side $positionSide, float $qty): string
    {
        $exchangeOrderId = $this->sendPlaceOrderRequest(
            PlaceOrderRequest::marketBuy(self::ASSET_CATEGORY, $symbol, $positionSide, $qty),
            static function (ApiErrorInterface $error) use ($symbol, $positionSide, $qty) {
                match ($error->code()) {
                    ApiV5Errors::CannotAffordOrderCost->value => throw CannotAffordOrderCostException::forBuy(
                        $symbol,
                        $positionSide,
                        $qty,
                    ),
                    default => null
                };
            },
        );

        $this->positionsCache->clearPositionsCache($symbol);

        return $exchangeOrderId;
    }

    /**
     * @inheritDoc
     *
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\Trade\ByBitOrderServiceTest\MarketBuyTest
     */
    public function closeByMarket(Position $position, float $qty): string
    {
        $symbol = $position->symbol;
        $qty = $symbol->roundVolume($qty);

        $exchangeOrderId = $this->sendPlaceOrderRequest(
            PlaceOrderRequest::marketClose(self::ASSET_CATEGORY, $symbol, $position->side, $qty)
        );

        $this->positionsCache->clearPositionsCache($symbol);

        return $exchangeOrderId;
    }

    /**
     * @inheritDoc
     *
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
    private function sendPlaceOrderRequest(AbstractByBitApiRequest $request, Closure $apiErrorResolvers = null): string
    {
        $calledServiceMethod = debug_backtrace()[1]['function'];

        $orderId = $this->sendRequest(
            $request,
            $apiErrorResolvers,
            $calledServiceMethod
        )->data()['orderId'] ?? null;

        if (!$orderId) {
            throw BadApiResponseException::cannotFindKey($request, 'result.`orderId`', __METHOD__);
        }

        return $orderId;
    }
}
