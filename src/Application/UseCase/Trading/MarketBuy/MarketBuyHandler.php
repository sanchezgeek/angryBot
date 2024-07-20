<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\MarketBuy;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\MarketBuy\Exception\BuyIsNotSafeException;
use App\Application\UseCase\Trading\Sandbox\Dto\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\ExecutionSandboxFactory;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Domain\Price\Price;
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
        if (!$this->buyIsSafe($dto)) {
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
        try {
            $symbol = $dto->symbol;
            $positionSide = $dto->positionSide;
            $ticker = $this->exchangeService->ticker($symbol);
            $sandbox = $this->executionSandboxFactory->make($symbol);
            $newState = $sandbox->processOrders(self::entryDtoToSandboxBuyOrder($dto, $ticker->lastPrice));
            $newPositionState = $newState->getPosition($positionSide);
            $newPositionLiquidation = $newPositionState->liquidationPrice();

            return $positionSide->isShort()
                ? $newPositionLiquidation->sub($this->safePriceDistance)->greaterThan($ticker->markPrice)
                : $newPositionLiquidation->add($this->safePriceDistance)->lessThan($ticker->markPrice);
        } catch (Throwable $e) {
            OutputHelper::warning(sprintf('%s: got "%s" exception while make `buyIsSafe` check', get_class($e), $e->getMessage()));
            return true;
        }
    }

    private static function entryDtoToSandboxBuyOrder(MarketBuyEntryDto $marketBuyDto, Price $price): SandboxBuyOrder
    {
        return new SandboxBuyOrder($marketBuyDto->symbol, $marketBuyDto->positionSide, Price::toFloat($price), $marketBuyDto->volume);
    }

    /**
     * @param ByBitOrderService $orderService
     */
    public function __construct(
        private readonly OrderServiceInterface $orderService,
        private readonly ExecutionSandboxFactory $executionSandboxFactory,
        private readonly ExchangeServiceInterface $exchangeService,
        private float $safePriceDistance = self::SAFE_PRICE_DISTANCE_DEFAULT
    ) {
    }
}