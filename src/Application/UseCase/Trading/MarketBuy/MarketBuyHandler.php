<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\MarketBuy;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\MarketBuy\Exception\BuyIsNotSafeException;
use App\Application\UseCase\Trading\Sandbox\Dto\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\ByBit\Service\Trade\ByBitOrderService;
use Throwable;

use function sprintf;

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
        if (!$dto->force && !$this->buyIsSafe($dto)) {
            throw new BuyIsNotSafeException();
        }

        return $this->orderService->marketBuy($dto->symbol, $dto->positionSide, $dto->volume);
    }

    /**
     * @internal For now only for tests
     */
    public function setSafeLiquidationPriceDistance(float $distance): void
    {
        $this->safePriceDistance = $distance;
    }

    private function buyIsSafe(MarketBuyEntryDto $dto): bool
    {
        $positionSide = $dto->positionSide;
        $symbol = $dto->symbol;
        $ticker = $this->exchangeService->ticker($symbol);
        $sandbox = $this->sandboxFactory->byCurrentState($symbol);

        try {
            $newState = $sandbox->processOrders(SandboxBuyOrder::fromMarketBuyEntryDto($dto, $ticker->lastPrice));
            $liquidationPrice = $newState->getPosition($positionSide)->liquidationPrice();
        } catch (Throwable $e) {
            # @todo + logger?
            OutputHelper::warning(sprintf('%s: got "%s" exception while make `buyIsSafe` check', __CLASS__, $e->getMessage()));

            # current position liquidation
            $liquidationPrice = $this->positionService->getPosition($dto->symbol, $dto->positionSide)->liquidationPrice();
        }

        return $positionSide->isShort()
            ? $liquidationPrice->sub($this->safePriceDistance)->greaterOrEquals($ticker->markPrice)
            : $liquidationPrice->add($this->safePriceDistance)->lessOrEquals($ticker->markPrice);
    }

    /**
     * @param ByBitOrderService $orderService
     */
    public function __construct(
        private readonly OrderServiceInterface $orderService,
        private readonly TradingSandboxFactoryInterface $sandboxFactory,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionServiceInterface $positionService,
        private float $safePriceDistance = self::SAFE_PRICE_DISTANCE_DEFAULT
    ) {
    }
}