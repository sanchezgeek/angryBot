<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\MarketBuy;

use App\Application\UseCase\Trading\MarketBuy\Checks\MarketBuyCheckService;
use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\MarketBuy\Exception\BuyIsNotSafeException;
use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\ByBit\Service\Trade\ByBitOrderService;

class MarketBuyHandler
{
    const SAFE_PRICE_DISTANCE_DEFAULT = 3000;

    /**
     * @throws BuyIsNotSafeException
     *
     * @throws CannotAffordOrderCostException
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     */
    public function handle(MarketBuyEntryDto $dto): string
    {
        $this->makeChecks($dto);

        return $this->orderService->marketBuy($dto->symbol, $dto->positionSide, $dto->volume);
    }

    /**
     * @internal For now only for tests
     */
    public function setSafeLiquidationPriceDistance(float $distance): void
    {
        $this->safePriceDistance = $distance;
    }

    /**
     * @throws BuyIsNotSafeException
     */
    private function makeChecks(MarketBuyEntryDto $dto): void
    {
        if ($dto->force) {
            return;
        }

        $symbol = $dto->symbol;
        $ticker = $this->exchangeService->ticker($symbol);
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
            currentState: $currentState,
            safePriceDistance: $this->safePriceDistance,
        );
    }

    /**
     * @param ByBitOrderService $orderService
     */
    public function __construct(
        private readonly MarketBuyCheckService        $marketBuyCheckService,
        private readonly OrderServiceInterface        $orderService,
        private readonly ExchangeServiceInterface     $exchangeService,
        private readonly SandboxStateFactoryInterface $sandboxStateFactory,
        private float                                 $safePriceDistance = self::SAFE_PRICE_DISTANCE_DEFAULT
    ) {
    }
}
