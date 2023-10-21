<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Order\ExecutionOrderType;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\BadApiResponseException;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\V5\Enum\Order\TriggerBy;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\CancelOrderRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\GetCurrentOrdersRequest;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use App\Infrastructure\ByBit\Service\Exception\Market\TickerNotFoundException;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Worker\AppContext;

use function is_array;
use function sprintf;
use function strtolower;

/**
 * @todo | now only for `linear` AssetCategory
 */
final class ByBitLinearExchangeService implements ExchangeServiceInterface
{
    use ByBitApiCallHandler;

    private const ASSET_CATEGORY = AssetCategory::linear;

    private string $workerHash;

    public function __construct(ByBitApiClientInterface $apiClient, ?string $workerHash = null)
    {
        $this->apiClient = $apiClient;
        $this->workerHash = $workerHash ?? AppContext::workerHash();
    }

    /**
     * @throws TickerNotFoundException
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService\GetTickerTest
     */
    public function ticker(Symbol $symbol): Ticker
    {
        $request = new GetTickersRequest(self::ASSET_CATEGORY, $symbol);

        $data = $this->sendRequest($request)->data();
        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        $ticker = null;
        foreach ($list as $item) {
            if ($item['symbol'] === $symbol->value) {
                $updatedBy = $this->workerHash;
                $ticker = new Ticker($symbol, (float)$item['markPrice'], (float)$item['indexPrice'], $updatedBy);
            }
        }

        if (!$ticker) {
            throw TickerNotFoundException::forSymbolAndCategory($symbol, self::ASSET_CATEGORY);
        }

        return $ticker;
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService\GetActiveConditionalOrdersTest
     */
    public function activeConditionalOrders(Symbol $symbol): array
    {
        $data = $this->sendRequest($request = GetCurrentOrdersRequest::openOnly(self::ASSET_CATEGORY, $symbol))->data();
        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        $activeOrders = [];
        foreach ($list as $item) {
            $reduceOnly = $item['reduceOnly'];
            $closeOnTrigger = $item['closeOnTrigger'];

            // Only orders created by bot
            if (
                !($reduceOnly && !$closeOnTrigger)
                || ($item['orderType'] ?? null) === ExecutionOrderType::Limit->value
            ) {
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
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService\CloseActiveConditionalOrderTest
     */
    public function closeActiveConditionalOrder(ActiveStopOrder $order): void
    {
        $request = CancelOrderRequest::byOrderId(self::ASSET_CATEGORY, $order->symbol, $order->orderId);
        $data = $this->sendRequest($request)->data();

        if ($data['orderId'] !== $order->orderId) {
            throw BadApiResponseException::common($request, sprintf('got another orderId (%s insteadof %s)', $data['orderId'], $order->orderId), __METHOD__);
        }
    }
}
