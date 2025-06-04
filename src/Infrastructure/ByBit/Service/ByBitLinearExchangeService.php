<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service;

use App\Bot\Application\Service\Exchange\Exchange\InstrumentInfoDto;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Order\ExecutionOrderType;
use App\Domain\Coin\Coin;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\PriceRange;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\BadApiResponseException;
use App\Infrastructure\ByBit\API\Common\Exception\PermissionDeniedException;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\V5\Request\Kline\GetKlinesRequest;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetInstrumentInfoRequest;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\CancelOrderRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\GetCurrentOrdersRequest;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use App\Infrastructure\ByBit\Service\Exception\Market\TickerNotFoundException;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Trading\Application\Symbol\Exception\SymbolNotFoundException;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateTimeImmutable;
use InvalidArgumentException;

use function is_array;
use function sprintf;
use function strtolower;

/**
 * @todo | now only for `linear` AssetCategory
 */
final class ByBitLinearExchangeService implements ExchangeServiceInterface
{
    use ByBitApiCallHandler;

    private const AssetCategory ASSET_CATEGORY = AssetCategory::linear;

    private string $workerHash;

    public function __construct(
        ByBitApiClientInterface $apiClient,
        private readonly SymbolProvider $symbolProvider,
    ) {
        $this->apiClient = $apiClient;
    }

    /**
     * @throws TickerNotFoundException
     *
     * @throws ApiRateLimitReached
     * @throws PermissionDeniedException
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws SymbolNotFoundException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService\GetTickerTest
     */
    public function ticker(SymbolInterface $symbol): Ticker
    {
        $request = new GetTickersRequest(self::ASSET_CATEGORY, $symbol);

        $data = $this->sendRequest($request)->data();
        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        $ticker = null;
        foreach ($list as $item) {
            if ($item['symbol'] === $symbol->name()) {
                $ticker = new Ticker(
                    $this->symbolProvider->replaceEnumWithEntity($symbol),
                    (float)$item['markPrice'],
                    (float)$item['indexPrice'],
                    (float)$item['lastPrice'],
                );
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
     * @throws PermissionDeniedException
     *
     * @throws SymbolNotFoundException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService\GetActiveConditionalOrdersTest
     */
    public function activeConditionalOrders(?SymbolInterface $symbol = null, ?PriceRange $priceRange = null): array
    {
        if (!$symbol && $priceRange) {
            throw new InvalidArgumentException('Wrong usage: cannot apply priceRange when Symbol not specified');
        }

        $data = $this->sendRequest($request = GetCurrentOrdersRequest::openOnly(self::ASSET_CATEGORY, $symbol))->data();
        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        $activeOrders = [];
        foreach ($list as $item) {
            $reduceOnly = $item['reduceOnly'];
            $closeOnTrigger = $item['closeOnTrigger'];

            if ($symbol && $symbol->name() !== $item['symbol']) {
                continue;
            }
            $itemSymbol = $this->symbolProvider->getOneByName($item['symbol']);

            // Only orders created by bot
            if (
                !($reduceOnly && !$closeOnTrigger)
                || ($item['orderType'] ?? null) === ExecutionOrderType::Limit->value
            ) {
                continue;
            }

            $orderId = $item['orderId'];

            $activeOrders[$orderId] = new ActiveStopOrder(
                $itemSymbol,
                Side::from(strtolower($item['side']))->getOpposite(),
                $orderId,
                (float)$item['qty'],
                (float)$item['triggerPrice'],
                TriggerBy::from($item['triggerBy'])->value,
            );
        }

        if ($priceRange) {
            $activeOrders = array_filter($activeOrders, static function(ActiveStopOrder $order) use ($priceRange) {
                return $priceRange->isPriceInRange($order->triggerPrice);
            });
        }

        return $activeOrders;
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
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

    public function getInstrumentInfo(SymbolInterface|string $symbol): InstrumentInfoDto
    {
        $request = new GetInstrumentInfoRequest(self::ASSET_CATEGORY, $symbol);
        $data = $this->sendRequest($request)->data();

        return new InstrumentInfoDto(
            (float)$data['list'][0]['lotSizeFilter']['minOrderQty'],
            (float)$data['list'][0]['lotSizeFilter']['minNotionalValue'],
            (float)$data['list'][0]['leverageFilter']['minLeverage'],
            (float)$data['list'][0]['leverageFilter']['maxLeverage'],
            (float)$data['list'][0]['priceFilter']['tickSize'],
            (int)$data['list'][0]['priceScale'],
            $data['list'][0]['quoteCoin'],
            $data['list'][0]['contractType'],
        );
    }

    /**
     * @return string[]
     *
     * @throws ApiRateLimitReached
     * @throws PermissionDeniedException
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     */
    public function getAllAvailableSymbolsRaw(Coin $settleCoin): array
    {
        $request = new GetTickersRequest(self::ASSET_CATEGORY, null, $settleCoin);

        $data = $this->sendRequest($request)->data();
        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        return array_map(static fn(array $item) => $item['symbol'], $list);
    }

    /**
     * @return Ticker[]
     *
     * @throws ApiRateLimitReached
     * @throws PermissionDeniedException
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     *
     * @throws SymbolNotFoundException
     */
    public function getAllTickers(Coin $settleCoin): array
    {
        $request = new GetTickersRequest(self::ASSET_CATEGORY, null, $settleCoin);

        $data = $this->sendRequest($request)->data();
        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        $result = [];
        foreach ($list as $item) {
            $result[] = new Ticker(
                $this->symbolProvider->getOneByName($item['symbol']),
                (float)$item['markPrice'],
                (float)$item['indexPrice'],
                (float)$item['lastPrice'],
            );
        }

        return $result;
    }

    public function getCandles(SymbolInterface $symbol, DateTimeImmutable $from, DateTimeImmutable $to, int $interval = 15, ?int $limit = null): array
    {
        $request = new GetKlinesRequest(self::ASSET_CATEGORY, $symbol, $interval, $from, $to, $limit);
        $data = $this->sendRequest($request)->data();

        $result = [];
        foreach (array_reverse($data['list']) as $item) {
            $result[] = [
                'time' => $item[0] / 1000,
                'open' => (float)$item[1],
                'high' => (float)$item[2],
                'low' => (float)$item[3],
                'close' => (float)$item[4],
            ];
        }

        return $result;
    }
}
