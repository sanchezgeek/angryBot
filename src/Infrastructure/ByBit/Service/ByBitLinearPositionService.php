<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use App\Infrastructure\ByBit\API\Common\ByBitApiClientInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\BadApiResponseException;
use App\Infrastructure\ByBit\API\Common\Exception\PermissionDeniedException;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\API\Common\Result\ApiErrorInterface;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Infrastructure\ByBit\API\V5\Request\Position\SetLeverageRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Infrastructure\ByBit\Service\Common\ByBitApiCallHandler;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\Trade\TickerOverConditionalOrderTriggerPrice;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;

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

    private const ASSET_CATEGORY = AssetCategory::linear;

    private const SLEEP_INC = 5;
    protected int $lastSleep = 0;

    private array $lastMarkPrices = [];

    public function __construct(
        ByBitApiClientInterface $apiClient,
        private readonly LoggerInterface $appErrorLogger,
    ) {
        $this->apiClient = $apiClient;
    }

    public function setLeverage(Symbol $symbol, float $forBuy, float $forSell): void
    {
        $request = new SetLeverageRequest(self::ASSET_CATEGORY, $symbol, $forBuy, $forSell);
        $this->sendRequest($request);
    }

    /**
     * @return Symbol[]
     */
    public function getOpenedPositionsSymbols(array $except = []): array
    {
        $symbolsRaw = $this->getOpenedPositionsRawSymbols();

        $symbols = [];
        foreach ($symbolsRaw as $rawItem) {
            if ($symbol = Symbol::tryFrom($rawItem)) {
                $symbols[] = $symbol;
            }
        }

        return array_values(
            array_filter($symbols, static fn(Symbol $symbol): bool => !in_array($symbol, $except, true))
        );
    }

    /**
     * @return string[]
     */
    public function getOpenedPositionsRawSymbols(): array
    {
        $request = new GetPositionsRequest(self::ASSET_CATEGORY, null);

        $data = $this->sendRequest($request)->data();

        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        $items = [];
        foreach ($list as $item) {
            if ((float)$item['avgPrice'] === 0.0) {
                continue;
            }
            $items[] = $item['symbol'];
        }

        return array_unique($items);
    }

    /**
     * @return array<Position[]>
     *
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
     * @throws ApiRateLimitReached
     * @throws PermissionDeniedException
     */
    public function getAllPositions(): array
    {
        $request = new GetPositionsRequest(self::ASSET_CATEGORY, null);
        $data = $this->sendRequest($request)->data();

        if (!is_array($list = $data['list'] ?? null)) {
            throw BadApiResponseException::invalidItemType($request, 'result.`list`', $list, 'array', __METHOD__);
        }

        /** @var Position[] $positions */
        $positions = [];
        foreach ($list as $item) {
            $side = Side::from(strtolower($item['side']));
            $symbol = Symbol::from($item['symbol']);

            $opposite = $positions[$symbol->value][$side->getOpposite()->value] ?? null;
            if ((float)$item['avgPrice'] !== 0.0) {
                $position = $this->parsePositionFromData($item);
                if ($opposite) {
                    $position->setOppositePosition($opposite);
                    $opposite->setOppositePosition($position);
                }
                $positions[$symbol->value][$side->value] = $position;
            }

            $this->lastMarkPrices[$symbol->value] = $symbol->makePrice((float)$item['markPrice']);
        }

        return $positions;
    }

    /**
     * @return array<string, Price>
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

    private function parsePositionFromData(array $apiData): Position
    {
        return new Position(
            Side::from(strtolower($apiData['side'])),
            Symbol::from($apiData['symbol']),
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
