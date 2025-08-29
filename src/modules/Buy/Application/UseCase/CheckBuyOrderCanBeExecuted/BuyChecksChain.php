<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted;

use App\Application\AttemptsLimit\AttemptLimitCheckerProviderInterface;
use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Buy\Application\Helper\BuyOrderInfoHelper;
use App\Helper\OutputHelper;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;
use App\Trading\SDK\Check\Exception\TooManyTriesForCheck;
use App\Trading\SDK\Check\Result\CommonOrderCheckFailureEnum as CheckFailures;

final class BuyChecksChain
{
    /** @var TradingCheckInterface[] */
    private array $checks;

    public function __construct(
        private readonly AttemptLimitCheckerProviderInterface $attemptLimitCheckerProvider,
        TradingCheckInterface ...$checks
    ) {
        $this->checks = $checks;
    }

    public function check(MarketBuyEntryDto $marketBuyEntryDto, TradingCheckContext $context): AbstractTradingCheckResult
    {
        $failed = false;
        $results = [];
        foreach ($this->checks as $check) {
            $orderCheckDto = new MarketBuyCheckDto($marketBuyEntryDto, $context->ticker);

            if (!$check->supports($orderCheckDto, $context)) {
                continue;
            }

            try {
                $result = $check->check($orderCheckDto, $context);
            } catch (TooManyTriesForCheck) {
                $result = TradingCheckResult::failed($check, CheckFailures::TooManyTries, 'Too many tries => no result => order must not be executed', true); // quiet
            } catch (UnexpectedSandboxExecutionException $e) {
                $result = TradingCheckResult::failed($check, CheckFailures::UnexpectedSandboxException, sprintf('sandbox error (%s)', $e->getMessage()));
            }

            if (!$result->success) {
                $failed = true;
            }

            $results[] = $result;
        }

        $source = sprintf('BUY %s %s', $marketBuyEntryDto->symbol->name(), $marketBuyEntryDto->positionSide->title());

        if ($failed) {
            $results = array_filter($results, static fn (AbstractTradingCheckResult $result) => !$result->quiet);
            return TradingCheckResult::failed($source, CheckFailures::ChecksChainFailed, self::info($marketBuyEntryDto, $context, ...$results), !$this->isNegativeMustBeShown($marketBuyEntryDto));
        }

        return TradingCheckResult::succeed($source, self::info($marketBuyEntryDto, $context, ...$results), !$results);
    }

    private static function info(MarketBuyEntryDto $marketBuyEntryDto, TradingCheckContext $context, AbstractTradingCheckResult ...$results): string
    {
        $orderPrice = $marketBuyEntryDto->sourceBuyOrder ? $marketBuyEntryDto->sourceBuyOrder->getPrice() : $context->ticker->markPrice;
        $orderInfo = sprintf(
            '%s(%s)',
            $marketBuyEntryDto->sourceBuyOrder ? BuyOrderInfoHelper::identifier($marketBuyEntryDto->sourceBuyOrder, ' ') : '',
            BuyOrderInfoHelper::shortInlineInfo($marketBuyEntryDto->volume, $orderPrice),
        );

        return sprintf('%s: %s', $orderInfo, self::joinedInfo(...$results));
    }

    private static function joinedInfo(AbstractTradingCheckResult ...$results): string
    {
        return $results
            ? implode('  |i|  ', array_map(static fn(AbstractTradingCheckResult $result) => $result->info(), $results))
            : 'no supported checks'
        ;
    }

    private function isNegativeMustBeShown(MarketBuyEntryDto $marketBuyEntryDto): bool
    {
        $throttlingKey = sprintf('BuyChecksChain_%s_%s', $marketBuyEntryDto->symbol->name(), $marketBuyEntryDto->positionSide->value);
        if ($marketBuyEntryDto->sourceBuyOrder) {
            $throttlingKey .= sprintf('_b_id_%d', $marketBuyEntryDto->sourceBuyOrder->getId());
        }

        // every 2 minutes
        return $this->attemptLimitCheckerProvider->get($throttlingKey, 240)->attemptIsAvailable();
    }
}
