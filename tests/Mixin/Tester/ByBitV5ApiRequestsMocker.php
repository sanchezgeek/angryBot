<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Tester;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiError;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Request\Account\GetWalletBalanceRequest;
use App\Infrastructure\ByBit\API\V5\Request\Asset\Balance\GetAllCoinsBalanceRequest;
use App\Infrastructure\ByBit\API\V5\Request\Asset\Transfer\CoinInterTransferRequest;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\CancelOrderRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\GetCurrentOrdersRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Tests\Mixin\Tester\ByBitApiRequests\ByBitApiCallExpectation;
use App\Tests\Mock\Response\ByBitV5Api\Account\GetWalletBalanceResponseBuilder;
use App\Tests\Mock\Response\ByBitV5Api\Account\AllCoinsBalanceResponseBuilder;
use App\Tests\Mock\Response\ByBitV5Api\CancelOrderResponseBuilder;
use App\Tests\Mock\Response\ByBitV5Api\Coin\CoinInterTransferResponseBuilder;
use App\Tests\Mock\Response\ByBitV5Api\PlaceOrderResponseBuilder;
use App\Tests\Mock\Response\ByBitV5Api\PositionResponseBuilder;
use App\Tests\Mock\Response\ByBitV5Api\TickersResponseBuilder;
use App\Tests\Mock\Response\ByBitV5Api\Trade\CurrentOrdersResponseBuilder;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

use function array_merge;
use function spl_object_hash;
use function sprintf;
use function uuid_create;

trait ByBitV5ApiRequestsMocker
{
    use ByBitV5ApiTester;

    /**
     * @doto pass coin instead
     */
    protected function haveAvailableSpotBalance(Symbol $symbol, float $amount): void
    {
        $amount = new CoinAmount($symbol->associatedCoin(), $amount);

        $expectedRequest = new GetAllCoinsBalanceRequest(AccountType::FUNDING, $symbol->associatedCoin());
        $resultResponse = AllCoinsBalanceResponseBuilder::ok()->withAvailableFundBalance($amount)->build();

        $this->matchGet($expectedRequest, $resultResponse, false);
    }

    protected function expectsInterTransferFromContractToSpot(CoinAmount $coinAmount): void
    {
        $transferId = uuid_create();
        $expectedRequest = CoinInterTransferRequest::test($coinAmount, AccountType::UNIFIED, AccountType::FUNDING);
        $resultResponse = CoinInterTransferResponseBuilder::ok($transferId)->build();

        $this->expectsToMakeApiCalls(new ByBitApiCallExpectation($expectedRequest, $resultResponse));
    }

    protected function expectsInterTransferFromSpotToContract(CoinAmount $coinAmount): void
    {
        $transferId = uuid_create();
        $expectedRequest = CoinInterTransferRequest::test($coinAmount, AccountType::FUNDING, AccountType::UNIFIED);
        $resultResponse = CoinInterTransferResponseBuilder::ok($transferId)->build();

        $this->expectsToMakeApiCalls(new ByBitApiCallExpectation($expectedRequest, $resultResponse));
    }

    protected function haveContractWalletBalance(Symbol $symbol, float $total, float $available): void
    {
        if ($available > $total) {
            throw new RuntimeException('$available cannot be greater than $total');
        }

        // guess it's opened position affects available
        $totalPositionIM = $total - $available;

        $expectedRequest = new GetWalletBalanceRequest(AccountType::UNIFIED, $symbol->associatedCoin());
        $resultResponse = GetWalletBalanceResponseBuilder::ok()->withUnifiedBalance($symbol->associatedCoin(), $total, $totalPositionIM)->build();

        $this->matchGet($expectedRequest, $resultResponse, false);
    }

    /**
     * To mock situation, when there is some available balance (to pass "leverage" check)
     * @see \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler::LEVERAGE_SLEEP_RANGES
     *
     * @todo | Is it actual? | Rename?
     */
    protected function haveContractWalletBalanceAllUsedToOpenPosition(Position $position): void
    {
        $positionCost = $position->initialMargin->value();
        $amountAvailable = $positionCost / 2;

        $this->haveContractWalletBalance($position->symbol, $positionCost + $amountAvailable, $amountAvailable);

        $this->havePosition($position->symbol, $position);
    }

    protected function expectsToMakeApiCalls(ByBitApiCallExpectation ...$expectations): void
    {
        foreach ($expectations as $expectation) {
            $method = $expectation->expectedRequest->method();

            if ($method === Request::METHOD_POST) {
                $this->matchPost($expectation->expectedRequest, $expectation->resultResponse, $expectation->isNeedTrackRequestCallToFurtherCheck(), $expectation->requestKey);
            } else {
                $method !== Request::METHOD_GET && throw new RuntimeException(
                    sprintf('Unknown request type (`%s` verb)', $method)
                );
                $this->matchGet($expectation->expectedRequest, $expectation->resultResponse, $expectation->isNeedTrackRequestCallToFurtherCheck(), $expectation->requestKey);
            }
        }
    }

    /**
     * @param BuyOrder[]|MarketBuyEntryDto[] $buyOrders
     *
     * @return ByBitApiCallExpectation[]
     */
    protected static function successMarketBuyApiCallExpectations(Symbol $symbol, array $buyOrders, array &$exchangeOrderIdsCollector = null): array
    {
        $result = [];
        foreach ($buyOrders as $buyOrder) {
            $buyOrder = $buyOrder instanceof MarketBuyEntryDto ? $buyOrder : MarketBuyEntryDto::fromBuyOrder($buyOrder);

            $exchangeOrderId = uuid_create();
            if ($exchangeOrderIdsCollector !== null) {
                $exchangeOrderIdsCollector[] = $exchangeOrderId;
            }

            $result[] = new ByBitApiCallExpectation(
                PlaceOrderRequest::marketBuy($symbol->associatedCategory(), $symbol, $buyOrder->positionSide, $buyOrder->volume),
                PlaceOrderResponseBuilder::ok($exchangeOrderId)->build(),
            );
        }

        return $result;
    }

    protected static function cannotAffordBuyApiCallExpectations(Symbol $symbol, array $buyOrders): array
    {
        $error = ByBitV5ApiError::knownError(ApiV5Errors::CannotAffordOrderCost, 'Cannot afford');

        $result = [];
        foreach ($buyOrders as $buyOrder) {
            $result[] = new ByBitApiCallExpectation(
                PlaceOrderRequest::marketBuy($symbol->associatedCategory(), $symbol, $buyOrder->getPositionSide(), $buyOrder->getVolume()),
                PlaceOrderResponseBuilder::error($error)->build(),
            );
        }

        return $result;
    }

    protected static function successCloseByMarketApiCallExpectation(Symbol $symbol, Side $positionSide, float $qty): ByBitApiCallExpectation
    {
        $exchangeOrderId = uuid_create();

        $expectedRequest = PlaceOrderRequest::marketClose($symbol->associatedCategory(), $symbol, $positionSide, $qty);
        $resultResponse = PlaceOrderResponseBuilder::ok($exchangeOrderId)->build();

        return new ByBitApiCallExpectation($expectedRequest, $resultResponse);
    }

    protected static function successCloseActiveConditionalOrderApiCallExpectation(Symbol $symbol, ActiveStopOrder $activeStopOrder): ByBitApiCallExpectation
    {
        $expectedRequest = CancelOrderRequest::byOrderId($activeStopOrder->symbol->associatedCategory(), $activeStopOrder->symbol, $activeStopOrder->orderId);
        $resultResponse = CancelOrderResponseBuilder::ok($activeStopOrder->orderId)->build();

        return new ByBitApiCallExpectation($expectedRequest, $resultResponse);
    }

    protected static function tickerApiCallExpectation(Ticker $ticker): ByBitApiCallExpectation
    {
        $symbol = $ticker->symbol;

        $expectedRequest = new GetTickersRequest($symbol->associatedCategory(), $symbol);
        $resultResponse = TickersResponseBuilder::ok($ticker)->build();

        return new ByBitApiCallExpectation($expectedRequest, $resultResponse);
    }

    protected static function positionsApiCallExpectation(Symbol $symbol, Position ...$positions): ByBitApiCallExpectation
    {
        $expectedRequest = new GetPositionsRequest($symbol->associatedCategory(), $symbol);

        $resultResponse = new PositionResponseBuilder($symbol->associatedCategory());
        foreach ($positions as $position) {
            if ($position->symbol !== $symbol) {
                throw new LogicException(sprintf('Position with invalid ::symbol provided (%s provided, but %s expected)', $position->symbol->value, $symbol->value));
            }
            $resultResponse->withPosition($position);
        }

        return new ByBitApiCallExpectation($expectedRequest, $resultResponse->build());
    }

    protected function haveTicker(Ticker $ticker): void
    {
        $byBitApiCallExpectation = self::tickerApiCallExpectation($ticker);
        $byBitApiCallExpectation->setNoNeedToTrackRequestCallToFurtherCheck();

        $this->expectsToMakeApiCalls($byBitApiCallExpectation);
    }

    protected function havePosition(Symbol $symbol, Position ...$pre): void
    {
        $positions = [];
        foreach ($pre as $position) {
            $positions[spl_object_hash($position)] = $position;
        }

        $oppositePositions = [];
        foreach ($positions as $position) {
            if ($position->oppositePosition) {
                $oppositePositions[spl_object_hash($position->oppositePosition)] = $position->oppositePosition;
            }
        }

        $byBitApiCallExpectation = self::positionsApiCallExpectation($symbol, ...array_merge($positions, $oppositePositions));
        $byBitApiCallExpectation->setNoNeedToTrackRequestCallToFurtherCheck();

        $this->expectsToMakeApiCalls($byBitApiCallExpectation);
    }

    /**
     * @param array<float, Position> $data
     */
    protected function haveAllOpenedPositionsWithLastMarkPrices(array $data): void
    {
        $category = $data[array_key_first($data)]->symbol->associatedCategory();
        $assetCategory = AssetCategory::linear;
        $expectedRequest = new GetPositionsRequest($assetCategory, null);

        $resultResponse = new PositionResponseBuilder($assetCategory);
        foreach ($data as $lastMarkPrice => $position) {
            if ($category !== $position->symbol->associatedCategory()) {
                throw new RuntimeException('Only for same AssetCategory');
            }
            $resultResponse->withPosition($position, (float)$lastMarkPrice);
        }

        $this->expectsToMakeApiCalls(
            new ByBitApiCallExpectation($expectedRequest, $resultResponse->build())
        );
    }

    private function haveActiveConditionalStops(Symbol $symbol, ActiveStopOrder ...$activeStopOrders): void
    {
        $category = $symbol->associatedCategory();

        $apiResponseBuilder = CurrentOrdersResponseBuilder::ok($category);
        foreach ($activeStopOrders as $activeStopOrder) {
            $apiResponseBuilder->withActiveConditionalStop(
                $symbol,
                $activeStopOrder->positionSide,
                $activeStopOrder->orderId ?: uuid_create(),
                $activeStopOrder->triggerPrice,
                $activeStopOrder->volume,
            );
        }

        $this->expectsToMakeApiCalls(
            new ByBitApiCallExpectation(GetCurrentOrdersRequest::openOnly($category, $symbol), $apiResponseBuilder->build())
        );
    }

    private function haveActiveConditionalStopsOnMultipleSymbols(ActiveStopOrder ...$activeStopOrdersArr): void
    {
        $category = AssetCategory::linear;
        $apiResponseBuilder = CurrentOrdersResponseBuilder::ok($category);

        foreach ($activeStopOrdersArr as $activeStopOrder) {
            $symbol = $activeStopOrder->symbol;
            if ($symbol->associatedCategory() !== $category) {
                throw new RuntimeException('Only for same category');
            }
            $apiResponseBuilder->withActiveConditionalStop(
                $symbol,
                $activeStopOrder->positionSide,
                uuid_create(),
                $activeStopOrder->triggerPrice,
                $activeStopOrder->volume,
            );
        }

        $this->expectsToMakeApiCalls(
            new ByBitApiCallExpectation(GetCurrentOrdersRequest::openOnly($category, null), $apiResponseBuilder->build())
        );
    }
}
