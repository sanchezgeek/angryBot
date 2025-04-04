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
use App\Bot\Application\Settings\TradingSettings;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\ExchangeOrder;
use App\Helper\OutputHelper;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\ByBit\Service\Trade\ByBitOrderService;
use App\Settings\Application\Service\AppSettingsProvider;
use Throwable;

class MarketBuyHandler
{
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
        $symbol = $dto->symbol;

        if ($symbol === Symbol::BTCUSDT) {
            $this->makeChecks($dto);
        }

        $ticker = $this->exchangeService->ticker($symbol);
        $exchangeOrder = ExchangeOrder::roundedToMin($symbol, $dto->volume, $ticker->lastPrice);

        try {
            return $this->orderService->marketBuy($symbol, $dto->positionSide, $exchangeOrder->getVolume());
        } catch (CannotAffordOrderCostException $e) {
            throw $e;
        } catch (Throwable $e) {
            OutputHelper::print(sprintf('%s while try to buy %s (%s initial) on %s %s', $e->getMessage(), $exchangeOrder->getVolume(), $dto->volume, $symbol->value, $dto->positionSide->value));
            return $this->orderService->marketBuy($symbol, $dto->positionSide, $exchangeOrder->getVolume() + $symbol->minOrderQty() * 2);
        }
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
            currentSandboxState: $currentState,
            safePriceDistance: $this->settings->get(TradingSettings::MarketBuy_SafePriceDistance),
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
        private readonly AppSettingsProvider          $settings,
    ) {
    }
}
