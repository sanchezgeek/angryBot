<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Tester;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\V5\ByBitV5ApiError;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Enum\ApiV5Errors;
use App\Infrastructure\ByBit\API\V5\Request\Account\GetWalletBalanceRequest;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Tests\Mixin\Tester\ByBitApiRequests\ByBitApiCallExpectation;
use App\Tests\Mock\Response\ByBitV5Api\Account\AccountBalanceResponseBuilder;
use App\Tests\Mock\Response\ByBitV5Api\PlaceOrderResponseBuilder;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

use function sprintf;

trait ByBitV5ApiRequestsMocker
{
    use ByBitV5ApiTester;

    protected function haveSpotBalance(Symbol $symbol, float $amount): void
    {
        $coinAmount = new CoinAmount($coin = $symbol->associatedCoin(), $amount);

        $this->matchGet(
            new GetWalletBalanceRequest(AccountType::SPOT, $coin),
            AccountBalanceResponseBuilder::ok($symbol->associatedCategory())->withCoinBalance(AccountType::SPOT, $coinAmount)->build(),
        );
    }

    protected  function expectsToMakeApiCalls(ByBitApiCallExpectation ...$expectations): void
    {
        foreach ($expectations as $expectation) {
            $method = $expectation->expectedRequest->method();

            if ($method === Request::METHOD_POST) {
                $this->matchPost($expectation->expectedRequest, $expectation->resultResponse);
            } else {
                $method !== Request::METHOD_GET && throw new RuntimeException(
                    sprintf('Unknown request type (`%s` verb)', $method)
                );
                $this->matchGet($expectation->expectedRequest, $expectation->resultResponse);
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

        return new ByBitApiCallExpectation(
            PlaceOrderRequest::marketClose($symbol->associatedCategory(), $symbol, $positionSide, $qty),
            PlaceOrderResponseBuilder::ok($exchangeOrderId)->build(),
        );
    }
}