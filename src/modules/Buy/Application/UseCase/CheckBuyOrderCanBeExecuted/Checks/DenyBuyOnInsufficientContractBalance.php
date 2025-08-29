<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Domain\Position;
use App\Buy\Application\Helper\BuyOrderInfoHelper;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\MarketBuyCheckDto;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\BuyCheckFailureEnum;
use App\Domain\Price\SymbolPrice;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;

final readonly class DenyBuyOnInsufficientContractBalance implements TradingCheckInterface
{
    public const string ALIAS = 'BUY/BALANCE_check';

    public function __construct(
        private ByBitExchangeAccountService $exchangeAccountService,
    ) {
    }

    public function alias(): string
    {
        return self::ALIAS;
    }

    public function supports(CheckOrderDto|MarketBuyCheckDto $orderDto, TradingCheckContext $context): bool
    {
        return true;
    }

    public function check(CheckOrderDto|MarketBuyCheckDto $orderDto, TradingCheckContext $context): AbstractTradingCheckResult
    {
        $available = $this->exchangeAccountService->getContractWalletBalance($orderDto->symbol()->associatedCoin())->available();
        $info = sprintf('contractBalance.available = %f', $available);

        if (!($available > 0)) {
            return TradingCheckResult::failed($this, BuyCheckFailureEnum::InsufficientContractBalance, sprintf('insufficient contract balance (%s)', $info));
        }

        return TradingCheckResult::succeed($this, $info, true);
    }

    private function getFixationStopsCountBeforePositionEntry(TradingCheckContext $context): int
    {
        $position = $context->currentPositionState;

        return $this->stopsCache->get(
            sprintf('fixations_%s_%s', $position->symbol->name(), $position->side->value),
            fn() => $this->stopsQueryService->getFixationStopsCountBeforePositionEntry($position, $context->ticker->markPrice),
            300
        );
    }

    private function info(
        Position $position,
        MarketBuyEntryDto $order,
        SymbolPrice $orderPrice,
        SymbolPrice $positionEntryPrice,
        string $reason,
    ): string {
        $identifierInfo = $order->sourceBuyOrder ? BuyOrderInfoHelper::identifier($order->sourceBuyOrder, ' ') : '';

        return sprintf(
            '%s | %s(%s) | entry=%s | %s',
            $position,
            $identifierInfo,
            BuyOrderInfoHelper::shortInlineInfo($order->volume, $orderPrice),
            $positionEntryPrice,
            $reason
        );
    }

    private static function extractMarketBuyEntryDto(CheckOrderDto|MarketBuyCheckDto $entryDto): MarketBuyEntryDto
    {
        return $entryDto->inner;
    }
}
