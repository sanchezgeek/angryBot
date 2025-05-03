<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\MarketBuy;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\MarketBuy\Exception\BuyIsNotSafeException;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\BuyChecksChain;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\BuyCheckFailureEnum;
use App\Domain\Order\ExchangeOrder;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Exception\Trade\OrderDoesNotMeetMinimumOrderValue;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\ByBit\Service\Trade\ByBitOrderService;
use App\Trading\Application\Check\Dto\TradingCheckContext;

readonly class MarketBuyHandler
{
    /**
     * @param ByBitOrderService $orderService
     */
    public function __construct(
        private OrderServiceInterface $orderService,
        private ExchangeServiceInterface $exchangeService,
        private BuyChecksChain $checks,
    ) {
    }

    /**
     * # checks
     * @throws UnexpectedSandboxExecutionException
     * @throws BuyIsNotSafeException
     *
     * # buy
     * @throws CannotAffordOrderCostException
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws OrderDoesNotMeetMinimumOrderValue
     */
    public function handle(MarketBuyEntryDto $dto, TradingCheckContext $checksContext): string
    {
        $checksResult = $this->checks->check($dto, $checksContext);
        if (!$checksResult->success) {
            match ($checksResult->failedReason) {
                BuyCheckFailureEnum::FurtherLiquidationIsTooClose => throw new BuyIsNotSafeException($checksResult->info() ?? ''),
                default => throw new BuyIsNotSafeException(),
            };
        }

        $symbol = $dto->symbol;
        $ticker = $this->exchangeService->ticker($symbol);
        $exchangeOrder = ExchangeOrder::roundedToMin($symbol, $dto->volume, $ticker->lastPrice);

        try {
            $orderId = $this->orderService->marketBuy($symbol, $dto->positionSide, $exchangeOrder->getVolume());
        } catch (OrderDoesNotMeetMinimumOrderValue $e) {
            // Make `/v5/order/create` request: got unknown errCode 110094 (Order does not meet minimum order value 5USDT) while try to buy 160 (126 initial) on BROCCOLIUSDT sell
            OutputHelper::print(sprintf('%s %s: got "%s" while try to buy %s (%s initial)', $symbol->value, $dto->positionSide->value, $e->getMessage(), $exchangeOrder->getVolume(), $dto->volume));
            $orderId = $this->orderService->marketBuy($symbol, $dto->positionSide, $exchangeOrder->getVolume() + $symbol->minOrderQty() * 3);
        }

        $checksContext->resetState();

        if ($info = $checksResult->info()) {
            OutputHelper::warning($info);
        }

        return $orderId;
    }
}
