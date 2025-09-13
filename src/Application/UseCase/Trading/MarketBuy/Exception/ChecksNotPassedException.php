<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\MarketBuy\Exception;

use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\BuyOrderPlacedTooFarFromPositionEntry;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\FixationsFound;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\FurtherPositionLiquidationAfterBuyIsTooClose;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Dto\CompositeTradingCheckResult;
use Exception;

final class ChecksNotPassedException extends Exception
{
    public function __construct(public readonly AbstractTradingCheckResult $result, bool $short = false)
    {
        if ($this->result instanceof CompositeTradingCheckResult && $short) {
            $messages = [];

            foreach ($result->getResults() as $result) {
                $messages[] = match (true) {
                    $result instanceof FurtherPositionLiquidationAfterBuyIsTooClose => sprintf('liq. is too near [Δ=%s,safe=%s]', $result->actualDistance(), $result->safeDistance),
                    $result instanceof BuyOrderPlacedTooFarFromPositionEntry => sprintf('too.far.from.pos.entry[Δ=%s,allowed=%s]', $result->orderPricePercentChangeFromPositonEntry, $result->maxAllowedPercentChange),
                    $result instanceof FixationsFound => sprintf('fixations (%d) found', $result->count),
                    default => $result->info(),
                };
            }
        } else {
            $messages = [$this->result->info()];
        }

        parent::__construct(implode(', ', $messages));
    }
}
