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
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\ByBit\Service\Trade\ByBitOrderService;

readonly class MarketBuyHandler
{
    const SAFE_PRICE_DISTANCE = 3000;

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

    private function buyIsSafe(MarketBuyEntryDto $dto): bool
    {
        $symbol = $dto->symbol;
        $positionSide = $dto->positionSide;

        $ticker = $this->exchangeService->ticker($symbol);
        $sandbox = $this->executionSandboxFactory->make($symbol);
        $newState = $sandbox->processOrders(self::entryDtoToSandboxBuyOrder($dto, $ticker->lastPrice));
        $newPositionState = $newState->getPosition($positionSide);
        $newPositionLiquidation = $newPositionState->liquidationPrice();

        return $positionSide->isShort()
            ? $newPositionLiquidation->sub(self::SAFE_PRICE_DISTANCE)->greaterThan($ticker->markPrice)
            : $newPositionLiquidation->add(self::SAFE_PRICE_DISTANCE)->lessThan($ticker->markPrice)
        ;
    }

    private static function entryDtoToSandboxBuyOrder(MarketBuyEntryDto $marketBuyDto, Price $price): SandboxBuyOrder
    {
        return new SandboxBuyOrder($marketBuyDto->symbol, $marketBuyDto->positionSide, Price::toFloat($price), $marketBuyDto->volume);
    }

    /**
     * @param ByBitOrderService $orderService
     */
    public function __construct(
        private OrderServiceInterface $orderService,
        private ExecutionSandboxFactory $executionSandboxFactory,
        private ExchangeServiceInterface $exchangeService,
    ) {
    }
}