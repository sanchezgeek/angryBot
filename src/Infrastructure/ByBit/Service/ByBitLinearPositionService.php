<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\Exception\SizeCannotBeLessOrEqualsZeroException;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\BadApiResponseException;
use App\Infrastructure\ByBit\API\Common\Exception\PermissionDeniedException;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\Common\Result\ApiErrorInterface;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Enum\Position\PositionMode;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Infrastructure\ByBit\API\V5\Request\Position\SetLeverageRequest;
use App\Infrastructure\ByBit\API\V5\Request\Position\SwitchPositionModeRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\Trade\TickerOverConditionalOrderTriggerPrice;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Trading\Application\Symbol\Exception\SymbolEntityNotFoundException;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\QuoteCoinNotEqualsSpecifiedOneException;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception\UnsupportedAssetCategoryException;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateInterval;
use InvalidArgumentException;
use LogicException;
use Symfony\Contracts\Cache\CacheInterface;

use function array_filter;
use function array_unique;
use function array_values;
use function count;
use function end;
use function in_array;
use function is_array;
use function preg_match;
use function print_r;
use function sleep;
use function sprintf;
use function strtolower;

/**
 * @todo | now only for `linear` AssetCategory
 */
final class ByBitLinearPositionService implements PositionServiceInterface
{
    use ByBitApiCallHandler;

    private const AssetCategory ASSET_CATEGORY = AssetCategory::linear;

    private const int SLEEP_INC = 5;
    protected int $lastSleep = 0;

    private array $lastMarkPrices = [];

    public function __construct(
        ByBitApiClientInterface $apiClient,
        private readonly CacheInterface $cache,
        private readonly SymbolProvider $symbolProvider,
    ) {
        $this->apiClient = $apiClient;
    }

    public function setLeverage(SymbolInterface $symbol, float $forBuy, float $forSell): void
    {
        $request = new SetLeverageRequest(self::ASSET_CATEGORY, $symbol, $forBuy, $forSell);
        $this->sendRequest($request);
    }

    public function switchPositionMode(SymbolInterface $symbol, PositionMode $positionMode): void
    {
        $request = new SwitchPositionModeRequest(self::ASSET_CATEGORY, $symbol, $positionMode);
        $this->sendRequest($request);
    }


    /**
     * @return SymbolInterface[]
     *
     * @throws PermissionDeniedException
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
    */
    public function getOpenedPositionsSymbols(SymbolInterface ...$except): array
    {
        $request = new GetPositionsRequest(self::ASSET_CATEGORY, null);
        $data = $this->sendRequest($request)->data();

        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }
        $except = array_map(static fn(SymbolInterface $symbol) => $symbol->name(), $except);

        $items = [];
        foreach ($list as $item) {
            $symbolRaw = $item['symbol'];
            if (
                (float)$item['avgPrice'] === 0.0
                || in_array($symbolRaw, $except, true)
            ) {
                continue;
            }

            $items[$symbolRaw/*unique*/] = $symbolRaw;
        }

        $result = [];
        foreach ($items as $symbolRaw) {
            try {
                $result[] = $this->symbolProvider->getOrInitialize($symbolRaw);
            } catch (UnsupportedAssetCategoryException) {
                continue;
            }
        }

        return $result;
    }

    /**
     * @return Position[]
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     *
     * @throws SizeCannotBeLessOrEqualsZeroException
     */
    public function getPositionsWithLiquidation(): array
    {
        $result = [];
        foreach ($this->getAllPositions() as $symbolPositions) {
            foreach ($symbolPositions as $position) {
//                if ($position->isMainPosition() || $position->isPositionWithoutHedge()) {
                // @todo | liquidation | null
                if ($position->liquidationPrice !== 0.00) {
                    $result[] = $position;
                }
            }
        }

        return $result;
    }

    /**
     * @return Position[]
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     *
     * @throws SizeCannotBeLessOrEqualsZeroException
     */
    public function getPositionsWithoutLiquidation(): array
    {
        $result = [];
        foreach ($this->getAllPositions() as $symbolPositions) {
            foreach ($symbolPositions as $position) {
//                if ($position->isMainPosition() || $position->isPositionWithoutHedge()) {
                // @todo | liquidation | null
                if ($position->liquidationPrice === 0.00) {
                    $result[] = $position;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<Position[]>
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     *
     * @throws SizeCannotBeLessOrEqualsZeroException
     *
     * @todo some collection
     */
    public function getAllPositions(): array
    {
        $request = new GetPositionsRequest(self::ASSET_CATEGORY, null);
        $data = $this->sendRequest($request)->data();

        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        /** @var array<Position[]> $positions */
        $positions = [];
        foreach ($list as $item) {
            if ((float)$item['avgPrice'] !== 0.0) {
                try {
                    $position = $this->parsePositionFromData($item);
                } catch (UnsupportedAssetCategoryException) {
                    continue;
                }

                $symbol = $position->symbol;
                $side = $position->side;

                $opposite = $positions[$symbol->name()][$side->getOpposite()->value] ?? null;
                if ($opposite) {
                    $position->setOppositePosition($opposite);
                    $opposite->setOppositePosition($position);
                }
                $positions[$symbol->name()][$side->value] = $position;

                $this->lastMarkPrices[$symbol->name()] = $symbol->makePrice((float)$item['markPrice']);
            }
        }

        foreach ($positions as $symbolPositions) {
            $symbol = reset($symbolPositions)->symbol;
            $key = ByBitLinearPositionCacheDecoratedService::positionsCacheKey($symbol);
            $item = $this->cache->getItem($key)->set(array_values($symbolPositions))->expiresAfter(
                DateInterval::createFromDateString(ByBitLinearPositionCacheDecoratedService::POSITION_TTL)
            );
            $this->cache->save($item);
        }

        return $positions;
    }

    /**
     * @return array<string, SymbolPrice>
     */
    public function getLastMarkPrices(): array
    {
        return $this->lastMarkPrices;
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\GetPositionTest
     */
    public function getPosition(SymbolInterface $symbol, Side $side): ?Position
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
     * @return Position[]
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     *
     * @throws SizeCannotBeLessOrEqualsZeroException
     */
    public function getPositions(SymbolInterface $symbol): array
    {
        if ($symbol->associatedCategory() !== self::ASSET_CATEGORY) {
            throw new InvalidArgumentException('Unsupported symbol associated category');
        }

        $request = new GetPositionsRequest(self::ASSET_CATEGORY, $symbol);

        try {
            $data = $this->sendRequest($request)->data();
        } catch (PermissionDeniedException $e) {
            $this->sleep($e->getMessage());
            return [];
        }

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

    /**
     * @throws SizeCannotBeLessOrEqualsZeroException
     * @throws UnsupportedAssetCategoryException
     */
    private function parsePositionFromData(array $apiData): Position
    {
        return new Position(
            Side::from(strtolower($apiData['side'])),
            $this->symbolProvider->getOrInitialize($apiData['symbol']),
            (float)$apiData['avgPrice'],
            (float)$apiData['size'],
            (float)$apiData['positionValue'],
            (float)$apiData['liqPrice'],
            (float)$apiData['positionIM'],
            (int)$apiData['leverage'],
            (float)$apiData['unrealisedPnl'],
        );
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
     * @throws PermissionDeniedException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\AddStopTest
     */
    public function addConditionalStop(Position $position, float $price, float $qty, TriggerBy $triggerBy): string
    {
        $price = $position->symbol->makePrice($price);
        $qty = $position->symbol->roundVolume($qty);

        $request = PlaceOrderRequest::stopConditionalOrder(
            self::ASSET_CATEGORY,
            $position->symbol,
            $position->side,
            $qty,
            $price->value(),
            $triggerBy,
        );

        $result = $this->sendRequest($request, static function (ApiErrorInterface $error) use ($position) {
            $code = $error->code();
            $msg = $error->msg();

            if ($code === ApiV5Errors::MaxActiveCondOrdersQntReached->value) {
                throw new MaxActiveCondOrdersQntReached($msg);
            }

            if (
                in_array($code, [ApiV5Errors::BadRequestParams->value, ApiV5Errors::BadRequestParams2->value, ApiV5Errors::BadRequestParams3->value], true)
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

    protected function sleep(string $cause): void
    {
        $this->lastSleep += self::SLEEP_INC;

        print_r(sprintf('Sleep for %d seconds, because %s', $this->lastSleep, $cause) . PHP_EOL);
        sleep($this->lastSleep);

        if ($this->lastSleep > 15) {
            $this->lastSleep = 0;
        }
    }
}
