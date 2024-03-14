<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Tester;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiError;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Request\Account\GetWalletBalanceRequest;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\GetCurrentOrdersRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Tests\Mixin\Tester\ByBitApiRequests\ByBitApiCallExpectation;
use App\Tests\Mock\Response\ByBitV5Api\Account\AccountBalanceResponseBuilder;
use App\Tests\Mock\Response\ByBitV5Api\PlaceOrderResponseBuilder;
use App\Tests\Mock\Response\ByBitV5Api\PositionResponseBuilder;
use App\Tests\Mock\Response\ByBitV5Api\TickersResponseBuilder;
use App\Tests\Mock\Response\ByBitV5Api\Trade\CurrentOrdersResponseBuilder;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

use function sprintf;

trait ByBitV5ApiRequestsMocker
{
    use ByBitV5ApiTester;

    protected function haveAvailableSpotBalance(Symbol $symbol, float $amount): void
    {
        $coinAmount = new CoinAmount($symbol->associatedCoin(), $amount);

        $expectedRequest = new GetWalletBalanceRequest(AccountType::SPOT, $symbol->associatedCoin());
        $resultResponse = AccountBalanceResponseBuilder::ok()->withAvailableSpotBalance($coinAmount)->build();

        $this->matchGet($expectedRequest, $resultResponse, false);
    }

    protected function haveContractWalletBalance(Symbol $symbol, float $total, float $available): void
    {
        $expectedRequest = new GetWalletBalanceRequest(AccountType::CONTRACT, $symbol->associatedCoin());
        $resultResponse = AccountBalanceResponseBuilder::ok()->withContractBalance($symbol->associatedCoin(), $total, $available)->build();

        $this->matchGet($expectedRequest, $resultResponse, false);
    }

    protected  function expectsToMakeApiCalls(ByBitApiCallExpectation ...$expectations): void
    {
        foreach ($expectations as $expectation) {
            $method = $expectation->expectedRequest->method();

            if ($method === Request::METHOD_POST) {
                $this->matchPost($expectation->expectedRequest, $expectation->resultResponse, $expectation->isNeedTrackRequestCallToFurtherCheck());
            } else {
                $method !== Request::METHOD_GET && throw new RuntimeException(
                    sprintf('Unknown request type (`%s` verb)', $method)
                );
                $this->matchGet($expectation->expectedRequest, $expectation->resultResponse, $expectation->isNeedTrackRequestCallToFurtherCheck());
            }
        }
    }

    /**
     * @param BuyOrder[] $buyOrders
     *
     * @return ByBitApiCallExpectation[]
     */
    protected static function successMarketBuyApiCallExpectations(Symbol $symbol, array $buyOrders, array &$exchangeOrderIdsCollector = null): array
    {
        $result = [];
        foreach ($buyOrders as $buyOrder) {
            $exchangeOrderId = uuid_create();

            if ($exchangeOrderIdsCollector !== null) {
                $exchangeOrderIdsCollector[] = $exchangeOrderId;
            }

            $result[] = new ByBitApiCallExpectation(
                PlaceOrderRequest::marketBuy($symbol->associatedCategory(), $symbol, $buyOrder->getPositionSide(), $buyOrder->getVolume()),
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

        $this->expectsToMakeApiCalls(
            $byBitApiCallExpectation->setNoNeedToTrackRequestCallToFurtherCheck()
        );
    }

    protected function havePosition(Symbol $symbol, Position ... $positions): void
    {
        $byBitApiCallExpectation = self::positionsApiCallExpectation($symbol, ...$positions);
        $byBitApiCallExpectation->setNoNeedToTrackRequestCallToFurtherCheck();

        $this->expectsToMakeApiCalls($byBitApiCallExpectation);
    }

    private function haveActiveConditionalStops(Symbol $symbol, ActiveStopOrder ...$activeStopOrders): void
    {
        $category = $symbol->associatedCategory();

        $apiResponseBuilder = CurrentOrdersResponseBuilder::ok($category);
        foreach ($activeStopOrders as $activeStopOrder) {
            $apiResponseBuilder->withActiveConditionalStop(
                $symbol,
                $activeStopOrder->positionSide,
                uuid_create(),
                $activeStopOrder->triggerPrice,
                $activeStopOrder->volume,
            );
        }

        $this->expectsToMakeApiCalls(
            new ByBitApiCallExpectation(GetCurrentOrdersRequest::openOnly($category, $symbol), $apiResponseBuilder->build())
        );
    }
}