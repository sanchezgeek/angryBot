<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\BuyCheckFailureEnum;
use App\Trading\Application\Check\Contract\AbstractTradingCheckResult;
use App\Trading\Application\Check\Dto\TradingCheckContext;
use App\Trading\Application\Check\Dto\TradingCheckResult;
use App\Trading\Application\Check\Exception\TooManyTriesForCheck;

final class BuyChecksChain
{
    /** @var BuyCheckInterface[] */
    private array $checks;

    public function __construct(BuyCheckInterface ...$checks)
    {
        $this->checks = $checks;
    }

    /**
     * @throws UnexpectedSandboxExecutionException
     */
    public function check(MarketBuyEntryDto $marketBuyEntryDto, TradingCheckContext $context): AbstractTradingCheckResult
    {
        $results = [];
        foreach ($this->checks as $check) {
            if (!$check->supports($marketBuyEntryDto, $context)) {
                continue;
            }

            try {
                $result = $check->check($marketBuyEntryDto, $context);
            } catch (TooManyTriesForCheck) {
                return TradingCheckResult::failed(
                    $check,
                    BuyCheckFailureEnum::TooManyTries,
                    'Too many tries, so here is no result. But order must not be executed.'
                );
            }//catch (UnexpectedSandboxExecutionException)

            if (!$result->success) {
                return $result;
            }

            $results[] = $result;
        }

        $innerInfo = implode(' ,;, ', array_map(static fn(AbstractTradingCheckResult $result) => $result->info(), $results));
        $buyOrder = $marketBuyEntryDto->sourceBuyOrder;

        return TradingCheckResult::succeed(
            sprintf('BuyChecksChain%s', $buyOrder ? sprintf(' for BuyOrder.id=%d', $buyOrder->getId()) : ''),
            $innerInfo
        );
    }
}
