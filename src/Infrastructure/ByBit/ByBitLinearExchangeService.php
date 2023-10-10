<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit;

use App\Bot\Application\Exception\ApiRateLimitReached;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Result\ApiErrorInterface;
use App\Infrastructure\ByBit\API\Result\ByBitApiCallResult;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Error;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Enum\Order\TriggerBy;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\CancelOrderRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\GetCurrentOrdersRequest;
use App\Infrastructure\ByBit\Exception\ByBitTickerNotFoundException;
use App\Worker\AppContext;
use RuntimeException;

use function debug_backtrace;
use function sprintf;
use function strtolower;

/**
 * @todo | now only for `linear` AssetCategory
 */
final readonly class ByBitLinearExchangeService implements ExchangeServiceInterface
{
    private const ASSET_CATEGORY = AssetCategory::linear;

    private string $workerHash;

    public function __construct(private ByBitApiClientInterface $apiClient, ?string $workerHash = null)
    {
        $this->workerHash = $workerHash ?? AppContext::workerHash();
    }

    /**
     * @todo | apiV5 | RuntimeException -> some CommonApiException
     * @throws ApiRateLimitReached|RuntimeException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\ByBitLinearExchangeService\GetTickerTest
     */
    public function ticker(Symbol $symbol): Ticker
    {
        $data = $this->sendAndProcessCommonErrors(new GetTickersRequest(self::ASSET_CATEGORY, $symbol))->data();

        $ticker = null;
        foreach ($data['list'] as $item) {
            if ($item['symbol'] === $symbol->value) {
                $updatedBy = $this->workerHash;
                $ticker = new Ticker($symbol, (float)$item['markPrice'], (float)$item['indexPrice'], $updatedBy);
            }
        }

        \assert($ticker !== null, ByBitTickerNotFoundException::forSymbolAndCategory($symbol, self::ASSET_CATEGORY));

        return $ticker;
    }

    /**
     * @throws ApiRateLimitReached|RuntimeException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\ByBitLinearExchangeService\GetActiveConditionalOrdersTest
     */
    public function activeConditionalOrders(Symbol $symbol): array
    {
        $data = $this->sendAndProcessCommonErrors(GetCurrentOrdersRequest::openOnly(self::ASSET_CATEGORY, $symbol))->data();

        $activeOrders = [];
        foreach ($data['list'] as $item) {
            $reduceOnly = $item['reduceOnly'];
            $closeOnTrigger = $item['closeOnTrigger'];

            // Only orders created by bot
            if (!($reduceOnly && !$closeOnTrigger)) {
                continue;
            }

            $activeOrders[] = new ActiveStopOrder(
                $symbol,
                Side::from(strtolower($item['side']))->getOpposite(),
                $item['orderId'],
                (float)$item['qty'],
                (float)$item['triggerPrice'],
                TriggerBy::from($item['triggerBy'])->value,
            );
        }

        return $activeOrders;
    }

    /**
     * @throws ApiRateLimitReached|RuntimeException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\ByBitLinearExchangeService\CloseActiveConditionalOrderTest
     */
    public function closeActiveConditionalOrder(ActiveStopOrder $order): void
    {
        $data = $this->sendAndProcessCommonErrors(
            $request = CancelOrderRequest::byOrderId(self::ASSET_CATEGORY, $order->symbol, $order->orderId)
        )->data();

        if ($data['orderId'] !== $order->orderId) {
            throw new RuntimeException(
                sprintf('%s | make `%s`: got another orderId (%s insteadof %s)', __METHOD__, $request->url(), $data['orderId'], $order->orderId)
            );
        }
    }

    /**
     * @todo | apiV5 | maybe actual for all API-categories (before main errors processing) | for example for ApiRateLimitReached or CommonApiError
     *
     * @throws ApiRateLimitReached|RuntimeException
     */
    private function sendAndProcessCommonErrors(AbstractByBitApiRequest $request): ByBitApiCallResult
    {
        $result = $this->apiClient->send($request);

        if (!$result->isSuccess()) {
            $this->processCommonTradeRequestsError($result, $request, sprintf('%s::%s', static::class, debug_backtrace()[1]['function']));
        }

        return $result;
    }

    /**
     * @throws ApiRateLimitReached|RuntimeException
     */
    private function processCommonTradeRequestsError(
        ByBitApiCallResult $result,
        AbstractByBitApiRequest $request,
        string $calledFrom,
    ): void {
        if (!$result->isSuccess()) {
            match ($error = $result->error()) {
                ApiV5Error::ApiRateLimitReached => throw new ApiRateLimitReached(),
                default => $this->throwExceptionOnUnknownApiError($request, $error, $calledFrom),
            };
        }
    }

    private function throwExceptionOnUnknownApiError(AbstractByBitApiRequest $request, ApiErrorInterface $err, string $in): void
    {
        throw new RuntimeException(
            sprintf('%s | make `%s`: unknown errCode %d (%s)', $in, $request->url(), $err->code(), $err->desc())
        );
    }
}
