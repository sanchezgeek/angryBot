<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;
use App\Trading\SDK\Check\Exception\TooManyTriesForCheck;
use App\Trading\SDK\Check\Result\CommonOrderCheckFailureEnum;

final class BuyChecksChain
{
    /** @var TradingCheckInterface[] */
    private array $checks;

    public function __construct(TradingCheckInterface ...$checks)
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
            $orderCheckDto = new MarketBuyCheckDto($marketBuyEntryDto, $context->ticker);

            if (!$check->supports($orderCheckDto, $context)) {
                continue;
            }

            try {
                $result = $check->check($orderCheckDto, $context);
            } catch (TooManyTriesForCheck) {
                return TradingCheckResult::failed($check, CommonOrderCheckFailureEnum::TooManyTries, 'Too many tries => no result => order must not be executed', true); // quiet
            }//catch (UnexpectedSandboxExecutionException)

            if (!$result->success) {
                return $result;
            }

            $results[] = $result;
        }

        $innerInfo = $results
            ? implode(' ,;, ', array_map(static fn(AbstractTradingCheckResult $result) => $result->info(), $results))
            : 'no supported checks'
        ;

        return TradingCheckResult::succeed(
            sprintf('BuyChecksChain%s', $marketBuyEntryDto->sourceBuyOrder ? sprintf(' for BuyOrder.id=%d', $marketBuyEntryDto->sourceBuyOrder->getId()) : ''),
            $innerInfo,
            !$results
        );
    }
}
