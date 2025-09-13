<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks;

use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\MarketBuyCheckDto;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\BuyCheckFailureEnum;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;

final readonly class DenyBuyOnInsufficientContractBalance implements TradingCheckInterface
{
    public const string ALIAS = 'BALANCE';

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
        $contractBalance = $this->exchangeAccountService->getContractWalletBalance($orderDto->symbol()->associatedCoin());
        $available = $contractBalance->available();

        if (!($available > 0)) {
            return TradingCheckResult::failed($this, BuyCheckFailureEnum::InsufficientContractBalance, '');
        }

        return TradingCheckResult::succeed($this, '', true);
    }
}
