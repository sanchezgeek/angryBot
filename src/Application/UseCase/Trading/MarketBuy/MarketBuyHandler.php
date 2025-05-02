<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\MarketBuy;

use App\Application\UseCase\Trading\MarketBuy\Checks\Exception\TooManyTriesForCheck;
use App\Application\UseCase\Trading\MarketBuy\Checks\MarketBuyCheckService;
use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\MarketBuy\Exception\BuyIsNotSafeException;
use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Domain\Order\ExchangeOrder;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Exception\Trade\OrderDoesNotMeetMinimumOrderValue;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\ByBit\Service\Trade\ByBitOrderService;
use Symfony\Component\RateLimiter\RateLimiterFactory;

readonly class MarketBuyHandler
{
    /**
     * @throws BuyIsNotSafeException
     *
     * @throws CannotAffordOrderCostException
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws TooManyTriesForCheck
     */
    public function handle(MarketBuyEntryDto $dto): string
    {
        $symbol = $dto->symbol;

        $this->makeChecks($dto);

        $ticker = $this->exchangeService->ticker($symbol);
        $exchangeOrder = ExchangeOrder::roundedToMin($symbol, $dto->volume, $ticker->lastPrice);

        try {
            return $this->orderService->marketBuy($symbol, $dto->positionSide, $exchangeOrder->getVolume());
        } catch (OrderDoesNotMeetMinimumOrderValue $e) {
            // Make `/v5/order/create` request: got unknown errCode 110094 (Order does not meet minimum order value 5USDT) while try to buy 160 (126 initial) on BROCCOLIUSDT sell
            OutputHelper::print(sprintf('%s %s: got "%s" while try to buy %s (%s initial)', $symbol->value, $dto->positionSide->value, $e->getMessage(), $exchangeOrder->getVolume(), $dto->volume));
            return $this->orderService->marketBuy($symbol, $dto->positionSide, $exchangeOrder->getVolume() + $symbol->minOrderQty() * 3);
        }
    }

    /**
     * @throws BuyIsNotSafeException
     * @throws TooManyTriesForCheck
     */
    private function makeChecks(MarketBuyEntryDto $dto): void
    {
        if ($dto->force) {
            return;
        }

        $symbol = $dto->symbol;
        $ticker = $this->exchangeService->ticker($symbol);

        if (
            $this->checkFurtherPositionLiquidationAfterBuyLimiter
            && $dto->sourceBuyOrder
            && !$this->checkFurtherPositionLiquidationAfterBuyLimiter->create(
                (string)($sourceOrderId = $dto->sourceBuyOrder->getId())
            )->consume()->isAccepted()
        ) {
            throw new TooManyTriesForCheck(sprintf('Too many tries for check further position liquidation for order with id = %d', $sourceOrderId));
        }

        $currentState = $this->sandboxStateFactory->byCurrentTradingAccountState($symbol);

        /**
         * @todo | Need mechanism to disable some checks (or another way: just add required; mb some factory with huma readable options; and may it could ne some decorated chain)
         *   E.g. in case of SandboxInsufficientAvailableBalanceException further calculated liquidationPrice check will be terminated
         *   So sandbox inner checks must be divided in some categories:
         *
         * At least it may be two categories:
         *  1) expected
         *  2) unexpected
         *
         * And logging of unexpected
         */
        $this->marketBuyCheckService->doChecks(
            order: $dto,
            ticker: $ticker,
            currentSandboxState: $currentState,
        );
    }

    /**
     * @param ByBitOrderService $orderService
     */
    public function __construct(
        private MarketBuyCheckService        $marketBuyCheckService,
        private OrderServiceInterface        $orderService,
        private ExchangeServiceInterface     $exchangeService,
        private SandboxStateFactoryInterface $sandboxStateFactory,
        private ?RateLimiterFactory $checkFurtherPositionLiquidationAfterBuyLimiter,
    ) {
    }
}
